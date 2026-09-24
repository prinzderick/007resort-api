<?php

namespace Tests\Unit;

use App\Support\Http\ApiProblem;
use App\Support\Http\ProblemRenderer;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProblemRendererTest extends TestCase
{
    public function test_a_domain_status_extension_never_overwrites_the_http_status_member(): void
    {
        // order_state_invalid used to ship `"status": "DRAFT"` (a string) instead of 409, breaking RFC 7807 clients.
        $problem = ApiProblem::conflict('order_state_invalid', 'The order is DRAFT.', ['status' => 'DRAFT', 'orderId' => 'x']);

        $response = ProblemRenderer::render($problem, Request::create('/api/v1/payments', 'POST'));
        $body = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(409, $body['status']);
        $this->assertSame('order_state_invalid', $body['code']);
        $this->assertSame('DRAFT', $body['entityStatus'], 'the domain value is still available, under its own key');
        $this->assertSame('x', $body['orderId']);
    }

    public function test_other_reserved_members_are_not_overwritable_either(): void
    {
        $problem = ApiProblem::conflict('order_state_invalid', 'real detail', ['code' => 'evil', 'detail' => 'evil', 'title' => 'evil', 'type' => 'evil']);

        $body = ProblemRenderer::render($problem, Request::create('/api/v1/x', 'POST'))->getData(true);

        $this->assertSame('order_state_invalid', $body['code']);
        $this->assertSame('real detail', $body['detail']);
        $this->assertSame('Conflict', $body['title']);
        $this->assertSame('urn:r007:problem:order_state_invalid', $body['type']);
    }
}
