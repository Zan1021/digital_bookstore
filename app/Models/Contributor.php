<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A person credited on books (task 6.2, Req 16.1). The role (author, illustrator,
 * narrator) is per book, on the book_contributor link, since one person can write one
 * book and illustrate another.
 */
class Contributor extends Model
{
    public const ROLES = ['author', 'illustrator', 'narrator'];

    protected $fillable = ['name'];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_contributor')
            ->withPivot(['role', 'position'])->withTimestamps();
    }
}
