<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Http\Controllers\Controller;
use App\Models\CourseDefinition;
use App\Models\CourseFile;
use App\Models\Entitlement;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function download(Request $request, string $fileId, EntitlementService $entitlements, CourseAccess $access): StreamedResponse
    {
        $courseFile = CourseFile::where('published', true)->find($fileId);
        if ($courseFile) {
            $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
                ->where('status', 'published')->where('site_enabled', true)->find($courseFile->course_id);
            $requiredCapability = $courseFile->required_capability;
            $courseIds = $course ? [$course->id] : [];
            if ($courseIds === [] || ! $this->hasAnyCourseAccess($request->user(), $courseIds, $entitlements, $access, $requiredCapability, $courseFile)) {
                throw ApiProblem::notFound('Datei');
            }
            $path = $courseFile->storage_path;
            $disk = Storage::disk('local');
            $mime = $courseFile->mime_type ?: 'application/octet-stream';
        } else {
            $asset = MediaAsset::find($fileId);
            if (! $asset || $asset->status !== 'published' || ! in_array($asset->rights_status, ['licensed', 'public'], true)) {
                throw ApiProblem::notFound('Datei');
            }

            $courseIds = DB::table('question_media')
                ->join('content_question_revision', 'content_question_revision.id', '=', 'question_media.revision_id')
                ->join('module_content', 'module_content.question_id', '=', 'content_question_revision.question_id')
                ->join('course_module', 'course_module.module_id', '=', 'module_content.module_id')
                ->where('question_media.media_asset_id', $asset->id)
                ->where('content_question_revision.editorial_status', 'published')
                ->where(fn ($q) => $q->whereNull('content_question_revision.valid_from')->orWhere('content_question_revision.valid_from', '<=', now()))
                ->where(fn ($q) => $q->whereNull('content_question_revision.valid_to')->orWhere('content_question_revision.valid_to', '>=', now()->toDateString()))
                ->distinct()->pluck('course_module.course_id');
            if ($courseIds->isEmpty() || ! $this->hasAnyCourseAccess($request->user(), $courseIds->all(), $entitlements, $access, 'smarttrainer', null, $asset)) {
                throw ApiProblem::notFound('Datei');
            }

            $path = $asset->getRawOriginal('storage_path');
            $disk = Storage::disk('public');
            $mime = $asset->mime_type ?: 'application/octet-stream';
        }
        if (! is_string($path) || $path === '' || preg_match('#^https?://#i', $path) || ! $disk->exists($path)) {
            throw ApiProblem::notFound('Datei');
        }
        $size = (int) $disk->size($path);
        $range = $request->header('If-Range') ? null : $this->parseRange($request->header('Range'), $size);
        if ($range === false) {
            throw new ApiProblem(416, 'RANGE_NOT_SATISFIABLE', 'Dateibereich ungültig', null, headers: ['Content-Range' => 'bytes */'.$size, 'Accept-Ranges' => 'bytes']);
        }
        $start = $range[0] ?? 0;
        $end = $range[1] ?? max(0, $size - 1);
        $length = max(0, $end - $start + 1);
        $status = $range === null ? 200 : 206;
        $filename = basename(str_replace('\\', '/', $path));
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => $this->contentDisposition($filename),
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($range !== null) {
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }

        return response()->stream(function () use ($disk, $path, $start, $length) {
            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                return;
            }
            if ($start > 0) {
                fseek($stream, $start);
            }
            $remaining = $length;
            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($stream);
        }, $status, $headers);
    }

    private function hasAnyCourseAccess(
        User $user,
        array $courseIds,
        EntitlementService $entitlements,
        CourseAccess $access,
        ?string $capability = 'smarttrainer',
        ?CourseFile $file = null,
        ?MediaAsset $asset = null,
    ): bool {
        $memberships = TenantUser::where('user_id', $user->id)->where('role', 'learner')->where('status', 'active')->pluck('tenant_id');
        $tenants = Tenant::whereIn('id', $memberships)->whereIn('status', ['active', 'trial'])->get()->keyBy('id');
        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('status', 'published')->where('site_enabled', true)->whereIn('id', $courseIds)->get()->keyBy('id');

        foreach ($tenants as $tenant) {
            foreach ($courses as $course) {
                if ($file && ($file->category === 'download' || $file->required_capability === 'downloads')) {
                    $ordered = CourseFile::where('course_id', $course->id)->where('published', true)
                        ->where('category', 'download')->orderBy('sort_order')->orderBy('id')->get();
                    if ($access->allowsLimitedItem($tenant, $user, $course, 'downloads', $ordered, (string) $file->id)) {
                        return true;
                    }
                    continue;
                }

                if ($file && $file->required_capability === null) {
                    if ($access->hasCourseAccess($tenant, $user, $course)) {
                        return true;
                    }
                    continue;
                }

                if ($file && $file->required_capability !== null) {
                    if ($access->hasFeatureAccess($tenant, $user, $course, $file->required_capability)) {
                        return true;
                    }
                    continue;
                }

                if (! $file && ! $asset && $capability === null && $entitlements->hasAccess($tenant, $user, $course)) {
                    return true;
                }

                if (! $file && $asset) {
                    if ($entitlements->hasCapabilityAccess($tenant, $user, $course, 'smarttrainer')) {
                        return true;
                    }
                    $courseQuestionIds = DB::table('question_media')
                        ->join('content_question_revision', 'content_question_revision.id', '=', 'question_media.revision_id')
                        ->join('module_content', 'module_content.question_id', '=', 'content_question_revision.question_id')
                        ->join('course_module', 'course_module.module_id', '=', 'module_content.module_id')
                        ->where('question_media.media_asset_id', $asset->id)
                        ->where('course_module.course_id', $course->id)
                        ->where('content_question_revision.editorial_status', 'published')
                        ->where(fn ($q) => $q->whereNull('content_question_revision.valid_from')->orWhere('content_question_revision.valid_from', '<=', now()))
                        ->where(fn ($q) => $q->whereNull('content_question_revision.valid_to')->orWhere('content_question_revision.valid_to', '>=', now()->toDateString()))
                        ->distinct()->pluck('content_question_revision.question_id');
                    if ($courseQuestionIds->intersect($access->accessibleQuestionIds($tenant, $user, $course))->isNotEmpty()) {
                        return true;
                    }
                    continue;
                }

                if ($capability !== null && $access->hasFeatureAccess($tenant, $user, $course, $capability)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{0:int,1:int}|null|false */
    private function parseRange(?string $header, int $size): array|null|false
    {
        if (!$header || stripos($header, 'bytes=') !== 0) {
            return null;
        }
        $spec = trim(substr($header, 6));
        if ($size <= 0 || preg_match('/^(\d*)-(\d*)$/', $spec, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return false;
        }
        if ($m[1] === '') {
            $suffix = (int) $m[2];
            return $suffix > 0 ? [max(0, $size - $suffix), $size - 1] : false;
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

        return $start < $size && $end >= $start ? [$start, $end] : false;
    }

    private function contentDisposition(string $filename): string
    {
        $filename = str_replace(["\r", "\n", "\0"], '', $filename);
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename) ?: 'download');
        $ascii = trim($ascii, '_') ?: 'download';

        return 'inline; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename);
    }
}
