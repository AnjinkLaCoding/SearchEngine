<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class FileDocument extends Model
{
    use Searchable;

    protected $fillable = ['id', 'title', 'file_name', 'file_size', 'mime_type', 'content', 'indexed_at'];

    // Scout index data
    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'content' => $this->content,
            'indexed_at' => $this->indexed_at,
        ];
    }
}
