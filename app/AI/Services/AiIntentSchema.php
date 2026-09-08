<?php

namespace App\AI\Services;

class AiIntentSchema
{
    public static function assigneeClarification(): array
    {
        return [
            'type' => 'object',

            'properties' => [
                'assignee_name' => [
                    'type' => ['string', 'null'],
                ],

                'open_assignment' => [
                    'type' => 'boolean',
                ],
            ],

            'required' => [
                'assignee_name',
                'open_assignment',
            ],

            'additionalProperties' => false,
        ];
    }

    public static function createTask(): array
    {
        return [
            'type' => 'object',

            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => [
                        'create_task',
                        'unknown',
                    ],
                ],

                'title' => [
                    'type' => ['string', 'null'],
                ],

                'description' => [
                    'type' => ['string', 'null'],
                ],

                /**
                 * Specific staff member identified by AI.
                 *
                 * Examples:
                 * - "Muzaffarga ber" → "Muzaffar"
                 * - "Muzaffar Tursunovga ber" → "Muzaffar Tursunov"
                 *
                 * null means no specific staff member was identified.
                 */
                'assignee_name' => [
                    'type' => ['string', 'null'],
                ],

                /**
                 * Determines the intended assignment.
                 *
                 * direct:
                 *     A specific staff member is identified.
                 *
                 * group:
                 *     No specific staff member is identified and the task
                 *     should be assigned to the current Telegram group.
                 */
                'assignment_type' => [
                    'type' => 'string',
                    'enum' => [
                        'direct',
                        'group',
                    ],
                ],

                'priority' => [
                    'type' => ['string', 'null'],
                    'enum' => [
                        'low',
                        'normal',
                        'high',
                        'urgent',
                        null,
                    ],
                ],

                'deadline' => [
                    'type' => ['string', 'null'],
                ],
            ],

            'required' => [
                'intent',
                'title',
                'description',
                'assignee_name',
                'assignment_type',
                'priority',
                'deadline',
            ],

            'additionalProperties' => false,
        ];
    }
    public static function messageDecision(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['create_task', 'update_task', 'not_task', 'ambiguous']],
                'confidence' => ['type' => 'number'],
                'target_task_id' => ['type' => ['integer', 'null']],
                'title' => ['type' => ['string', 'null']],
                'description' => ['type' => ['string', 'null']],
                'description_append' => ['type' => ['string', 'null']],
                'assignee_name' => ['type' => ['string', 'null']],
                'assignment_type' => ['type' => ['string', 'null'], 'enum' => ['direct', 'group', null]],
                'priority' => ['type' => ['string', 'null'], 'enum' => ['low', 'normal', 'high', 'urgent', null]],
                'deadline' => ['type' => ['string', 'null']],
            ],
            'required' => ['action', 'confidence', 'target_task_id', 'title', 'description', 'description_append', 'assignee_name', 'assignment_type', 'priority', 'deadline'],
            'additionalProperties' => false,
        ];
    }

}
