<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalProcess extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = ['created_by', 'name', 'approvable_type', 'version', 'priority', 'conditions', 'match_type', 'active', 'settings'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'priority' => 'integer', 'conditions' => 'array', 'active' => 'boolean', 'settings' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('position');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ApprovalRule::class)->orderBy('priority');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }
}
