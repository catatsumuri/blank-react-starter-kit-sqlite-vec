<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['document_id', 'chunk_index', 'heading', 'content', 'embed_text'])]
class DocumentChunk extends Model
{
}
