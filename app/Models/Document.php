<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slug', 'title', 'emoji', 'type', 'topics', 'published', 'published_at', 'content'])]
class Document extends Model
{
}
