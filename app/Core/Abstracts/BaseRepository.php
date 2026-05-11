<?php

namespace App\Core\Abstracts;

use App\Core\Contracts\RepositoryInterface;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository implements RepositoryInterface
{

    protected $model;

    /**
     * @throws BindingResolutionException
     */
    public function __construct()
    {
        $this->makeModel();
    }

    abstract public function model();

    /**
     * @throws BindingResolutionException
     */
    public function makeModel() {
        $model = app()->make($this->model());
        return $this->model = $model;
    }

    public function all($columns = ['*']) {
        return $this->model->get($columns);
    }

    public function find($id, $columns = ['*']) {
        return $this->model->find($id, $columns);
    }

    public function findByField($field, $value, $columns = ['*']) {
        return $this->model->where($field, $value)->get($columns);
    }

    public function findWhere(array $where, $columns = ['*']) {
        return $this->model->where($where)->first($columns);
    }

    public function findWhereIn($field, array $values, $columns = ['*']) {
        return $this->model->whereIn($field, $values)->get($columns);
    }

    public function findWhereNotIn($field, array $values, $columns = ['*']) {
        return $this->model->whereNotIn($field, $values)->get($columns);
    }

    public function findWhereBetween($field, array $values, $columns = ['*']) {
        return $this->model->whereBetween($field, $values)->get($columns);
    }

    public function findWhereNotBetween($field, array $values, $columns = ['*']) {
        return $this->model->whereNotBetween($field, $values)->get($columns);
    }

    public function findWhereNull($field, $columns = ['*']) {
        return $this->model->whereNull($field)->get($columns);
    }

    public function findWhereNotNull($field, $columns = ['*']) {
        return $this->model->whereNotNull($field)->get($columns);
    }

    public function findWhereNotNullIn($field, array $values, $columns = ['*']) {
        return $this->model->whereNotNullIn($field, $values)->get($columns);
    }

    public function create(array $data) {
        return $this->model->create($data);
    }

    public function update($id, array $data) {
        return $this->model->where('id', $id)->update($data);
    }

    public function delete($id) {
        return $this->model->where('id', $id)->delete();
    }

}
