<?php

namespace App\Support\Database;

use App\Support\Ids;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent builder that transparently converts canonical UUID strings to BINARY(16) for
 * columns declared as uuid columns on the model (primary key + $uuidColumns), so
 * find()/whereKey()/where()/whereIn()/relationships all work with canonical strings.
 *
 * @property Model $model
 */
class UuidBuilder extends Builder
{
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                is_numeric($key) && is_array($val)
                    ? $this->where(...array_values($val))
                    : $this->where($key, '=', $val, $boolean);
            }

            return $this;
        }

        if (is_string($column) && $this->isUuidColumn($column)) {
            [$value, $operator] = $this->query->prepareValueAndOperator($value, $operator, func_num_args() === 2);

            return parent::where($column, $operator, $this->toBinaryValue($value), $boolean);
        }

        return parent::where(...func_get_args());
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if (is_string($column) && $this->isUuidColumn($column)) {
            $values = $values instanceof Arrayable ? $values->toArray() : $values;
            $values = is_array($values) ? array_map($this->toBinaryValue(...), $values) : $values;
        }

        $this->query->whereIn($column, $values, $boolean, $not);

        return $this;
    }

    public function whereKey($id)
    {
        $id = $id instanceof Arrayable ? $id->toArray() : $id;
        $id = is_array($id) ? array_map($this->toBinaryValue(...), $id) : $this->toBinaryValue($id);

        return parent::whereKey($id);
    }

    public function whereKeyNot($id)
    {
        $id = $id instanceof Arrayable ? $id->toArray() : $id;
        $id = is_array($id) ? array_map($this->toBinaryValue(...), $id) : $this->toBinaryValue($id);

        return parent::whereKeyNot($id);
    }

    protected function isUuidColumn(string $column): bool
    {
        $bare = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

        return in_array($bare, $this->model->getUuidColumns(), true);
    }

    protected function toBinaryValue(mixed $value): mixed
    {
        return is_string($value) && strlen($value) !== 16 && Ids::isUuid($value) ? Ids::toBinary($value) : $value;
    }
}
