<?php

namespace App\Actions\Account;

use App\Jobs\DeleteReplacedAvatar;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateProfile
{
    public function handle(User $user, string $name, ?UploadedFile $avatar, bool $remove): void
    {
        $path = null;
        if ($avatar) {
            $image = @imagecreatefromstring($avatar->getContent());
            if (! $image) {
                throw ValidationException::withMessages(['avatar' => __('profile.avatar_rules')]);
            }
            if ($avatar->getMimeType() === 'image/jpeg' && function_exists('exif_read_data')) {
                $orientation = (int) (@exif_read_data($avatar->getRealPath())['Orientation'] ?? 1);
                if (in_array($orientation, [2, 5, 7], true)) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                } elseif ($orientation === 4) {
                    imageflip($image, IMG_FLIP_VERTICAL);
                }
                $angle = match ($orientation) {
                    3 => 180,
                    5, 8 => 90,
                    6, 7 => -90,
                    default => 0,
                };
                if ($angle) {
                    $rotated = imagerotate($image, $angle, 0);
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
            $output = imagecreatetruecolor(512, 512);
            imagefill($output, 0, 0, imagecolorallocate($output, 255, 255, 255));
            $side = min(imagesx($image), imagesy($image));
            imagecopyresampled($output, $image, 0, 0, (int) ((imagesx($image) - $side) / 2), (int) ((imagesy($image) - $side) / 2), 512, 512, $side, $side);
            ob_start();
            imagejpeg($output, null, 88);
            $contents = ob_get_clean();
            imagedestroy($image);
            imagedestroy($output);
            if (! is_string($contents) || $contents === '') {
                throw ValidationException::withMessages(['avatar' => __('profile.avatar_failed')]);
            }
            $path = 'avatars/'.$user->id.'/'.Str::uuid().'.jpg';
            if (! Storage::disk('local')->put($path, $contents)) {
                throw ValidationException::withMessages(['avatar' => __('profile.avatar_failed')]);
            }
        }

        try {
            $previous = DB::transaction(function () use ($user, $name, $path, $remove): ?string {
                $record = User::lockForUpdate()->findOrFail($user->id);
                $previous = $record->avatar_path;
                $record->name = $name;
                if ($path || $remove) {
                    $record->avatar_path = $path;
                }
                $record->save();

                return ($path || $remove) ? $previous : null;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        if ($previous) {
            try {
                (new DeleteReplacedAvatar($user->id, $previous))->handle();
            } catch (\Throwable) {
                DeleteReplacedAvatar::dispatch($user->id, $previous)->onQueue('low')->afterCommit();
            }
        }
    }
}
