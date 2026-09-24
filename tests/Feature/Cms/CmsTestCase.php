<?php

namespace Tests\Feature\Cms;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\DemoApi;
use Tests\Support\TestData;
use Tests\Support\TestResponseBuilder;
use Tests\TestCase;

/** Shared helpers: demo property (staff, service token), staff API builders per role, and fixture images. */
abstract class CmsTestCase extends TestCase
{
    use DemoApi;

    protected const SERVICE = 'r7s_dev_booking_web';

    /** @var array<string, TestResponseBuilder> */
    private array $apis = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set('cms.media.disk', 'public');
        $this->seedDemo();
    }

    protected function staff(string $username): TestResponseBuilder
    {
        return $this->apis[$username] ??= $this->api($username);
    }

    /** A staff member holding only the given role (e.g. MARKETING). */
    protected function roleApi(string $roleCode): TestResponseBuilder
    {
        $tenant = ['org' => DemoIds::org(), 'site' => DemoIds::site()];
        $u = strtolower($roleCode).bin2hex(random_bytes(3));
        $staff = TestData::staff($tenant, $u, TestData::PASSWORD, '1234');
        TestData::assign($staff, $roleCode, 'SITE');
        $r = $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => $u, 'secret' => '1234']);
        $r->assertOk();

        return new TestResponseBuilder($this, $r->json(), null);
    }

    protected function owner(): TestResponseBuilder
    {
        return $this->staff('owner1');
    }

    /** Public read/write as the website (service token). @return array<string, string> */
    protected function site(array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.self::SERVICE, 'Accept' => 'application/json'] + $extra;
    }

    protected function pub(string $path, array $headers = []): TestResponse
    {
        return $this->withHeaders($this->site($headers))->getJson('/api/v1/public/cms'.$path);
    }

    protected function pubPost(string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->withHeaders($this->site($headers))->postJson('/api/v1/public/cms'.$path, $body);
    }

    /** Create + publish a page/post/event/album through the admin API. @return array<string, mixed> resource */
    protected function published(string $resource, array $body, ?string $publishedAt = null): array
    {
        $o = $this->owner();
        $created = $o->post("/admin/cms/{$resource}", $body)->assertCreated()->json();

        return $o->post("/admin/cms/{$resource}/{$created['id']}/publish", $publishedAt ? ['publishedAt' => $publishedAt] : [])->assertOk()->json();
    }

    // ---- fixtures ----

    /** @return string bytes of a real image */
    protected function imageBytes(string $type = 'jpeg', int $w = 1200, int $h = 800, bool $exif = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 60, 40));
        imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), (int) ($w / 3), (int) ($h / 3), imagecolorallocate($im, 20, 120, 200));
        ob_start();
        match ($type) {
            'png' => imagepng($im), 'webp' => imagewebp($im), 'avif' => imageavif($im), default => imagejpeg($im, null, 90)
        };
        $bytes = (string) ob_get_clean();
        if ($exif && $type === 'jpeg') {
            $payload = "Exif\0\0SECRETGPS-4.9375N-6.2634E";
            $seg = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
            $bytes = substr($bytes, 0, 2).$seg.substr($bytes, 2);
        }

        return $bytes;
    }

    protected function upload(TestResponseBuilder $as, string $bytes, string $name = 'photo.jpg', array $fields = []): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent($name, $bytes);

        return $this->withHeaders($as->headers(['Accept' => 'application/json', 'Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))]))
            ->post('/api/v1/admin/cms/media', $fields + ['file' => $file]);
    }

    /** Upload a valid JPEG and return the Media resource. @return array<string, mixed> */
    protected function newMedia(string $alt = 'A test photo', int $w = 1200, int $h = 800): array
    {
        return $this->upload($this->owner(), $this->imageBytes('jpeg', $w, $h), 'p'.bin2hex(random_bytes(3)).'.jpg', ['alt' => $alt])->assertCreated()->json();
    }

    /** @return list<object> audit rows for an action */
    protected function audit(string $action, ?string $entityId = null): array
    {
        $q = DB::table('audit_log')->where('action', $action)->orderBy('seq');
        if ($entityId !== null) {
            $q->where('entity_id', Ids::toBinary($entityId));
        }

        return $q->get()->map(function ($r) {
            $r->old = $r->old_value === null ? null : json_decode($r->old_value, true);
            $r->new = $r->new_value === null ? null : json_decode($r->new_value, true);

            return $r;
        })->all();
    }

    protected function etag(TestResponse $r): array
    {
        return ['If-Match' => $r->headers->get('ETag')];
    }
}
