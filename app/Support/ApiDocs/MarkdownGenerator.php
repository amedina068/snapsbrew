<?php

namespace App\Support\ApiDocs;

/**
 * Renders documentation Markdown from {@see ApiSpec}. Produces one document per
 * group (a docs screen) or the full reference. Output is built on the fly.
 */
class MarkdownGenerator
{
    /** Markdown for a single docs group/screen. */
    public function group(string $slug): ?string
    {
        $group = ApiSpec::group($slug);
        if (! $group) {
            return null;
        }

        $out = [];
        $out[] = '# '.$group['title'];
        $out[] = '';
        $out[] = '> '.$group['tagline'];
        $out[] = '';
        $out[] = $this->paragraphs($group['description']);

        foreach ($group['endpoints'] as $endpoint) {
            $out[] = '';
            $out[] = $this->endpoint($endpoint);
        }

        return implode("\n", $out)."\n";
    }

    /** Markdown for the entire API reference. */
    public function full(): string
    {
        $info = ApiSpec::info();

        $out = [];
        $out[] = '# '.$info['title'].' Reference';
        $out[] = '';
        $out[] = $info['description'];
        $out[] = '';
        $out[] = '- Base URL: `'.$info['baseUrl'].'`';
        $out[] = '- Version: `'.ApiSpec::VERSION.'`';
        $out[] = '- Support: '.$info['contactEmail'];
        $out[] = '';
        $out[] = $info['rateLimits'];
        $out[] = '';

        foreach (ApiSpec::groups() as $group) {
            $out[] = '## '.$group['title'];
            $out[] = '';
            $out[] = $this->paragraphs($group['description']);
            foreach ($group['endpoints'] as $endpoint) {
                $out[] = '';
                $out[] = $this->endpoint($endpoint, 3);
            }
            $out[] = '';
        }

        $out[] = $this->schemaSection();

        return implode("\n", $out)."\n";
    }

    private function endpoint(array $endpoint, int $level = 2): string
    {
        $h = str_repeat('#', $level);
        $hh = str_repeat('#', $level + 1);

        $out = [];
        $out[] = $h.' '.$endpoint['summary'];
        $out[] = '';
        $out[] = '```http';
        // A route outside `/api` prints its full URL, because the base URL above
        // does not apply to it.
        $out[] = $endpoint['method'].' '.(($endpoint['base'] ?? 'api') === 'site' ? ApiSpec::info()['siteUrl'] : '').$endpoint['path'];
        $out[] = '```';
        $out[] = '';
        $out[] = $this->paragraphs($endpoint['description']);
        $out[] = '';

        $out[] = '- **Auth:** '.$this->authLabel($endpoint['auth']);
        if (! empty($endpoint['abilities'])) {
            $out[] = '- **Token abilities:** `'.implode('`, `', $endpoint['abilities']).'`';
        }
        if (! empty($endpoint['middleware'])) {
            $out[] = '- **Gates:** '.implode(', ', $endpoint['middleware']);
        }
        $out[] = '';

        if (! empty($endpoint['parameters'])) {
            $out[] = $hh.' Parameters';
            $out[] = '';
            $out[] = '| Name | In | Type | Required | Description |';
            $out[] = '| --- | --- | --- | --- | --- |';
            foreach ($endpoint['parameters'] as $param) {
                $out[] = sprintf(
                    '| `%s` | %s | %s | %s | %s |',
                    $param['name'],
                    $param['in'],
                    $param['type'],
                    ($param['required'] ?? false) ? 'Yes' : 'No',
                    $this->cell($param['description'] ?? '')
                );
            }
            $out[] = '';
        }

        if (! empty($endpoint['body'])) {
            $out[] = $hh.' Request body';
            $out[] = '';
            $out[] = 'Content-Type: `'.$endpoint['body']['contentType'].'`';
            $out[] = '';
            $out[] = '| Field | Type | Required | Validation | Description |';
            $out[] = '| --- | --- | --- | --- | --- |';
            foreach ($endpoint['body']['fields'] as $field) {
                $out[] = sprintf(
                    '| `%s` | %s | %s | %s | %s |',
                    $field['name'],
                    $field['type'],
                    ($field['required'] ?? false) ? 'Yes' : 'No',
                    $this->cell($field['rules'] ?? '—'),
                    $this->cell($field['description'] ?? '')
                );
            }
            $out[] = '';
        }

        if (! empty($endpoint['responses'])) {
            $out[] = $hh.' Responses';
            $out[] = '';
            foreach ($endpoint['responses'] as $response) {
                $out[] = '**'.$response['status'].'** — '.$response['description'];
                $out[] = '';
                $out[] = '```json';
                $out[] = json_encode($response['example'] ?? new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $out[] = '```';
                $out[] = '';
            }
        }

        foreach ($endpoint['notes'] ?? [] as $note) {
            $out[] = '> '.$note;
            $out[] = '';
        }

        if (! empty($endpoint['examples'])) {
            $out[] = $hh.' Examples';
            $out[] = '';
            foreach ($endpoint['examples'] as $label => $code) {
                $out[] = '*'.ucfirst($label).'*';
                $out[] = '';
                $out[] = '```bash';
                $out[] = $code;
                $out[] = '```';
                $out[] = '';
            }
        }

        return rtrim(implode("\n", $out));
    }

    private function schemaSection(): string
    {
        $out = ['## Object reference', ''];
        foreach (ApiSpec::schemas() as $name => $schema) {
            $out[] = '### '.$name;
            $out[] = '';
            $out[] = $schema['description'];
            $out[] = '';
            $out[] = '| Field | Type | Description |';
            $out[] = '| --- | --- | --- |';
            foreach ($schema['fields'] as $field) {
                $out[] = sprintf(
                    '| `%s` | %s | %s |',
                    $field['name'],
                    $field['type'],
                    $this->cell($field['description'])
                );
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    private function authLabel(string $auth): string
    {
        return match ($auth) {
            'bearer' => 'Bearer token',
            'signature' => 'Signed URL',
            default => 'None (public)',
        };
    }

    private function paragraphs(string $text): string
    {
        return trim($text);
    }

    private function cell(string $text): string
    {
        return trim(str_replace(["\n", '|'], [' ', '\\|'], $text));
    }
}
