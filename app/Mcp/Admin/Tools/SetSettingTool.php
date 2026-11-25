<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Mcp\Admin\InstanceSettingsAllowlist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('set-setting')]
#[Title('Set a setting')]
#[Description('Changes one setting from the allowlist that get-settings returns. Today the allowlist is registration_mode (open or invite_only). Settings from the environment cannot change here.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class SetSettingTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'value' => ['required', 'string'],
        ]);

        $allowed = InstanceSettingsAllowlist::KEYS;

        if (! array_key_exists($input['key'], $allowed)) {
            return self::error('setting_not_allowed', "The setting `{$input['key']}` cannot change through this tool.", 'Call `get-settings` to read the settings that can change.', ['allowed' => array_keys($allowed)]);
        }

        if (! in_array($input['value'], $allowed[$input['key']], true)) {
            return self::error('invalid_setting_value', "The value `{$input['value']}` is not allowed for `{$input['key']}`.", 'Use one of the values in `details.allowed`.', ['allowed' => $allowed[$input['key']]]);
        }

        $before = InstanceSettingsAllowlist::current()[$input['key']]['value'];
        InstanceSettingsAllowlist::set($input['key'], $input['value']);

        return self::ok(['key' => $input['key'], 'previous' => $before, 'value' => $input['value'], 'status' => $before === $input['value'] ? 'unchanged' : 'changed']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->enum(array_keys(InstanceSettingsAllowlist::KEYS))->description('The setting to change.')->required(),
            'value' => $schema->string()->description('The new value.  lists the allowed values.')->required(),
        ];
    }
}
