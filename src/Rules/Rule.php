<?php

namespace Ernestdefoe\Surveyor\Rules;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $type exact | wildcard | regex
 * @property string $pattern
 * @property string $action exclude | include
 * @property string $scope audit | indexnow | both
 * @property bool $enabled
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Rule extends AbstractModel
{
    protected $table = 'surveyor_rules';

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const TYPES = ['exact', 'wildcard', 'regex'];
    public const ACTIONS = ['exclude', 'include'];
    public const SCOPES = ['audit', 'indexnow', 'both'];

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'pattern' => $this->pattern,
            'action' => $this->action,
            'scope' => $this->scope,
            'enabled' => $this->enabled,
            'note' => $this->note,
        ];
    }
}
