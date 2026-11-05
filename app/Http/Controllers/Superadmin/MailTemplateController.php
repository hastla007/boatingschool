<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Support\MailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Mailvorlagen: Betreff, Text davor und danach je Mailart (siehe MailTemplates). */
class MailTemplateController extends Controller
{
    public function edit(): View
    {
        return view('superadmin.mail-templates.edit', [
            'definitions' => MailTemplates::DEFINITIONS,
            'stored' => PlatformSetting::current()->mail_templates ?? [],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (array_keys(MailTemplates::DEFINITIONS) as $key) {
            $rules["templates.{$key}.subject"] = ['nullable', 'string', 'max:200'];
            $rules["templates.{$key}.intro"] = ['nullable', 'string', 'max:3000'];
            $rules["templates.{$key}.outro"] = ['nullable', 'string', 'max:3000'];
        }
        $validated = $request->validate($rules);

        $templates = collect($validated['templates'] ?? [])
            ->map(fn ($t) => array_filter(array_map(fn ($v) => trim((string) $v), $t), fn ($v) => $v !== ''))
            ->filter()
            ->all();

        $settings = PlatformSetting::current();
        $before = $settings->mail_templates;
        $settings->update(['mail_templates' => $templates ?: null]);
        MailTemplates::flush();
        AuditLog::record(null, $request->user()->id, 'superadmin.mail_templates.update', 'platform_setting', null, ['mail_templates' => $before], ['mail_templates' => $templates]);

        return back()->with('status', 'Mailvorlagen gespeichert.');
    }
}
