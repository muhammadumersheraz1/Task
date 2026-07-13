<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function statuses(): array
{
    return ['new', 'in-progress', 'closed'];
}

function render_header(string $title): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="assets/style.css">';
    echo '</head><body><div class="wrap">';
    echo '<header class="top"><strong>Inquiry Tracker</strong><nav>';
    echo '<a href="list.php">List</a>';
    echo '<a href="add.php">Add</a>';
    echo '<a href="report.php">Report</a>';
    echo '</nav></header>';
}

function render_footer(string $extraJs = ''): void
{
    if ($extraJs !== '') {
        echo '<script>' . $extraJs . '</script>';
    }
    echo '</div></body></html>';
}