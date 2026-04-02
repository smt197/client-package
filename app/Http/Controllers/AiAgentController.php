<?php

namespace App\Http\Controllers;

use App\Models\AiAgent;
use Orion\Http\Controllers\Controller;
use Orion\Concerns\DisableAuthorization;

class AiAgentController extends Controller
{
    use DisableAuthorization;
    /**
     * The Eloquent model associated with this controller.
     *
     * @var string
     */
    protected $model = AiAgent::class;

    public function keyName(): string
    {
        return 'slug';
    }

    /**
     * Default pagination limit.
     */
    public function limit(): int
    {
        return config('app.limit_pagination', 15);
    }

    /**
     * Maximum pagination limit.
     */
    public function maxLimit(): int
    {
        return config('app.max_pagination', 100);
    }

    /**
     * The attributes that are used for searching.
     */
    public function searchableBy(): array
    {
        return ['name', 'slug', 'provider', 'model_name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'created_at', 'updated_at'];
    }

    public function filterableBy(): array
    {
        return ['name', 'provider', 'is_active'];
    }
}
