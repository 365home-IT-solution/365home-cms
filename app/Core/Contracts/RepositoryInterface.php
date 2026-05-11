<?php

namespace App\Core\Contracts;

interface RepositoryInterface
{
    public function all(array $columns = ['*']);
    public function find($id);
    public function findByField($field, $value);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
}
