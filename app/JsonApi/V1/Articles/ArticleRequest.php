<?php

namespace App\JsonApi\V1\Articles;

use Illuminate\Validation\Rule;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

class ArticleRequest extends ResourceRequest
{

    /**
     * Get the validation rules for the resource.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'title'      => ['required', 'string'],
            'slug'       => ['required', 'string'],
            'content'    => ['required', 'string'],
            'authors'    => ['required', JsonApiRule::toOne()],
            'categories' => ['required', JsonApiRule::toOne()],
        ];
    }

}
