<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Policies\MediaPolicy;
use App\Services\AuthService;
use App\Support\Contract;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MediaController
{
    public function photo(Request $r, AuthService $auth, MediaPolicy $policy)
    {
        Contract::allowed($r->query(), ['kind', 'id', 'w']);
        $id = Contract::id($r->query('id'));
        $width = null;
        if ($r->has('w')) {
            if ($r->query('w') !== '480' || $r->query('kind') !== 'item') {
                throw new ContractError('Invalid photo size.', 400);
            }$width = 480;
        }
        if ($r->query('kind') === 'item') {
            $u = $auth->require();
            $photo = DB::selectOne('SELECT file_path,photo_kind FROM item_photos WHERE photo_id=?', [$id]);
            if ($photo && $photo->photo_kind === 'OWNERSHIP' && ! $policy->privateEvidence($u)) {
                throw new ContractError('This action is not available to your role.', 403, 'forbidden');
            }$collection = ($photo->photo_kind ?? '') === 'OWNERSHIP' ? 'evidence' : 'items';
        } elseif ($r->query('kind') === 'claim') {
            $u = $auth->require();
            if (! $policy->privateEvidence($u)) {
                throw new ContractError('This action is not available to your role.', 403, 'forbidden');
            }$photo = DB::selectOne('SELECT file_path FROM claim_photos WHERE claim_photo_id=?', [$id]);
            $collection = 'claims';
        } else {
            throw new ContractError('Invalid photo collection.', 400);
        }
        if (! $photo || ! preg_match('~^'.$collection.'/[a-f0-9]{32}\.(jpg|png|webp)$~D', $photo->file_path)) {
            throw new ContractError('Photo not found.', 404, 'not_found');
        }
        $path = $this->path($photo->file_path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ContractError('Photo not found.', 404, 'not_found');
        }
        if ($width && ($thumb = $this->thumbnail($path, $photo->file_path, $width))) {
            $path = $thumb;
            $mime = 'image/jpeg';
        }
        $etag = '"'.hash('sha256', $photo->file_path.'|'.filesize($path).'|'.filemtime($path).'|'.($width ?? 0)).'"';
        $headers = ['Cache-Control' => 'private, no-cache', 'ETag' => $etag, 'X-Content-Type-Options' => 'nosniff'];
        if (in_array($etag, array_map('trim', explode(',', $r->header('If-None-Match', ''))), true)) {
            return response('', 304, $headers);
        }

        $response = response()->file($path, $headers + ['Content-Type' => $mime, 'Content-Length' => (string) filesize($path)])->setPrivate();
        $response->headers->remove('Pragma');

        return $response;
    }

    public function avatar(Request $r, AuthService $auth)
    {
        Contract::allowed($r->query(), ['token']);
        $auth->require();
        $token = $r->query('token', '');
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{32}$/D', $token)) {
            throw new ContractError('Picture not found.', 404, 'not_found');
        }$relative = 'avatars/'.$token.'.jpg';
        if (! DB::selectOne('SELECT user_id FROM users WHERE avatar_path=? AND is_active=1', [$relative])) {
            throw new ContractError('Picture not found.', 404, 'not_found');
        }

        return response()->file($this->path($relative), Contract::headers() + ['Content-Type' => 'image/jpeg'])->setPrivate();
    }

    private function path(string $relative): string
    {
        $path = Workspace::media().'/'.$relative;
        Workspace::assertPath($path);
        if (! is_file($path)) {
            throw new ContractError('Photo not found.', 404, 'not_found');
        }

        return $path;
    }

    private function thumbnail(string $source, string $relative, int $width): ?string
    {
        $target = Workspace::media().'/thumbs/'.hash('sha256', $relative).'-'.$width.'.jpg';
        Workspace::assertPath($target);
        if (is_file($target) && filemtime($target) >= filemtime($source)) {
            return $target;
        }
        $size = @getimagesize($source);
        if (! $size || $size[0] <= $width) {
            return null;
        }$image = @imagecreatefromstring(file_get_contents($source));
        if (! $image) {
            return null;
        }$height = max(1, (int) round($size[1] * $width / $size[0]));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
        $temporary = tempnam(dirname($target), 'thumb-');
        if (! $temporary) {
            return null;
        }if (! imagejpeg($canvas, $temporary, 80) || ! rename($temporary, $target)) {
            @unlink($temporary);

            return null;
        }

        return $target;
    }
}
