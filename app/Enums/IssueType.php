<?php

namespace App\Enums;

/**
 * The classification of an issue within a project's backlog.
 */
enum IssueType: string
{
    case Epic = 'epic';
    case Story = 'story';
    case Task = 'task';
    case Bug = 'bug';
}
