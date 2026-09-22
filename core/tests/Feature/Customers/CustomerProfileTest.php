<?php

use App\Domain\Access\UserWrites;
use App\Domain\Customers\CustomerAccounts;
use App\Domain\Media\MediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * `updateProfile`, `updatePassword`, `DELETE me/avatar` (storefront Phase 1, piece 3).
 *
 * Two properties carry this file. The first is that the CALLER IS THE TOKEN: the legacy app reached
 * these actions through `User::find($request->id)`, so any client could name somebody else's
 * account, and the IDOR fix is the behaviour being reproduced. The second is that the response
 * shapes are the ones the account screen already parses, because Phase 2 is an environment flip.
 */

const PROFILE_API_KEY = 'customer-profile-test-key';

beforeEach(function () {
    config([
        'compat.api_key' => PROFILE_API_KEY,
        'compat.jwt_secret' => 'customer-profile-test-secret',
        'compat.jwt_algo' => 'HS256',
    ]);
});

/** @return array<string, string> */
function profileHeaders(string $token): array
{
    return ['Api-Code' => PROFILE_API_KEY, 'Authorization' => 'Bearer '.$token];
}

/** A real PNG wrapped as an upload — the avatar equivalent of the media suite's helper. */
function fakeAvatar(string $name = 'avatar.png'): UploadedFile
{
    $image = imagecreatetruecolor(300, 300);
    $teal = imagecolorallocate($image, 20, 140, 140);
    imagefill($image, 0, 0, $teal === false ? 0 : $teal);
    $path = tempnam(sys_get_temp_dir(), 'avatar').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

// ── updateProfile ────────────────────────────────────────────────────────────────────────────

it('updates the caller own name and telephone and answers the legacy shape', function () {
    [$user, $token] = Shopper::withToken(['first_name' => 'Before', 'last_name' => 'Change']);

    $response = withHeaders(profileHeaders($token))->postJson('/api/updateProfile', [
        'first_name' => 'After',
        'last_name' => 'Change',
        'phone_number' => '01099887766',
    ])->assertOk();

    expect($response->json('message'))->toBe('Profile updated successfully')
        ->and($response->json('user.first_name'))->toBe('After')
        ->and($response->json('user.phone_number'))->toBe('01099887766');

    $row = T::one(DB::table('users')->where('id', $user->id));
    expect(T::str($row->first_name))->toBe('After')
        ->and(T::str($row->phone_number))->toBe('01099887766');
});

it('edits the TOKEN holder, never an id the caller put in the body', function () {
    /*
     * The IDOR the legacy app shipped and then fixed. Reproducing the endpoint means reproducing
     * the fixed version, and this is the assertion that says so rather than trusting the comment.
     */
    [$caller, $token] = Shopper::withToken(['first_name' => 'Caller']);
    $victim = Shopper::register(['first_name' => 'Victim']);

    withHeaders(profileHeaders($token))->postJson('/api/updateProfile', [
        'id' => $victim->id,
        'user_id' => $victim->id,
        'first_name' => 'Hijacked',
        'last_name' => 'Name',
    ])->assertOk();

    expect(T::str(DB::table('users')->where('id', $victim->id)->value('first_name')))->toBe('Victim')
        ->and(T::str(DB::table('users')->where('id', $caller->id)->value('first_name')))->toBe('Hijacked');
});

it('CLEARS a telephone when the customer sends an empty one', function () {
    // An explicit null has to survive: "remove my number" is a thing people do, and a filter that
    // drops empty values would silently refuse it.
    [$user, $token] = Shopper::withToken(['phone_number' => '01012345678']);

    withHeaders(profileHeaders($token))->postJson('/api/updateProfile', [
        'first_name' => 'Still', 'last_name' => 'Here', 'phone_number' => '',
    ])->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('phone_number'))->toBeNull();
});

it('accepts a name-only edit while the stored avatar URL is posted back as a string', function () {
    /*
     * The legacy bug this endpoint was fixed for: the `image` rule ran unconditionally, so the SPA
     * posting the existing avatar URL failed 422 on every edit that changed only a name. The rule
     * applies only when a FILE actually arrived, and this is what stops a tidy-up reintroducing it.
     */
    [, $token] = Shopper::withToken();

    withHeaders(profileHeaders($token))->postJson('/api/updateProfile', [
        'first_name' => 'Name', 'last_name' => 'Only',
        'image' => 'https://dash.watchizereg.com/Uploads_Images/User/old-avatar.webp',
    ])->assertOk();
});

it('REFUSES a nameless profile with the legacy error shape', function () {
    [, $token] = Shopper::withToken();

    $response = withHeaders(profileHeaders($token))->postJson('/api/updateProfile', [
        'first_name' => '', 'last_name' => '',
    ])->assertStatus(422);

    // `{"error": {field: [messages]}}` — hand-rolled on the legacy side, and reproduced rather than
    // tidied, because the storefront is not being changed this phase.
    expect($response->json('error'))->toHaveKey('first_name')
        ->and($response->json('error'))->toHaveKey('last_name');
});

// ── the avatar ───────────────────────────────────────────────────────────────────────────────

it('stores an avatar in the LEGACY folder under the legacy filename scheme', function () {
    [$user, $token] = Shopper::withToken();

    $response = withHeaders(profileHeaders($token))->post('/api/updateProfile', [
        'first_name' => 'With', 'last_name' => 'Photo',
        'image' => fakeAvatar(),
    ])->assertOk();

    $file = T::str($response->json('user.image'));

    /*
     * The database holds a bare FILENAME, never a path or a host (study §5.4), and the name follows
     * `<unix>_<Y-m-d>_<uniqid>.webp` so a file written here is indistinguishable from one the Blade
     * dashboard wrote into the same shared tree.
     */
    expect($file)->toMatch('/^\d+_\d{4}-\d{2}-\d{2}_[0-9a-f]+\.webp$/')
        ->and(T::str(DB::table('users')->where('id', $user->id)->value('image')))->toBe($file)
        ->and(is_file(MediaStore::directory('User').'/'.$file))->toBeTrue();

    // Clean up the real file this test wrote into the shared tree: the database row rolls back with
    // the transaction, the byte on disk does not.
    MediaStore::forgetFile(CustomerAccounts::AVATAR_TYPE, $file);
});

it('deletes the PREVIOUS avatar when a new one replaces it, and only after the row points at the new one', function () {
    [$user, $token] = Shopper::withToken();

    $first = T::str(withHeaders(profileHeaders($token))->post('/api/updateProfile', [
        'first_name' => 'One', 'last_name' => 'Photo', 'image' => fakeAvatar('first.png'),
    ])->assertOk()->json('user.image'));

    $second = T::str(withHeaders(profileHeaders($token))->post('/api/updateProfile', [
        'first_name' => 'Two', 'last_name' => 'Photos', 'image' => fakeAvatar('second.png'),
    ])->assertOk()->json('user.image'));

    expect($second)->not->toBe($first)
        ->and(T::str(DB::table('users')->where('id', $user->id)->value('image')))->toBe($second)
        ->and(is_file(MediaStore::directory('User').'/'.$second))->toBeTrue()
        // The old file is gone — and it was removed AFTER the row moved, so a failure anywhere in
        // between leaves a row pointing at a file that exists rather than one that does not.
        ->and(is_file(MediaStore::directory('User').'/'.$first))->toBeFalse();

    MediaStore::forgetFile(CustomerAccounts::AVATAR_TYPE, $second);
});

it('removes the avatar: the column is cleared AND the file is gone', function () {
    [$user, $token] = Shopper::withToken();

    $file = T::str(withHeaders(profileHeaders($token))->post('/api/updateProfile', [
        'first_name' => 'Has', 'last_name' => 'Photo', 'image' => fakeAvatar(),
    ])->assertOk()->json('user.image'));

    $response = withHeaders(profileHeaders($token))->deleteJson('/api/me/avatar')->assertOk();

    expect($response->json('message'))->toBe('Avatar removed')
        ->and($response->json('user.image'))->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('image'))->toBeNull()
        /*
         * Deleted, not merely unreferenced. "Remove my photo" that leaves the image resolving at
         * its old URL is not what was asked for, on the one action a customer takes for a privacy
         * reason — and this deletion infers nothing, unlike `media:prune`, because the row it is
         * clearing is the thing that named the file.
         */
        ->and(is_file(MediaStore::directory('User').'/'.$file))->toBeFalse();
});

it('is a no-op when there is no avatar to remove', function () {
    [, $token] = Shopper::withToken();

    withHeaders(profileHeaders($token))->deleteJson('/api/me/avatar')
        ->assertOk()->assertJsonPath('user.image', null);
});

it('REFUSES to delete a stored filename that is not a plain filename', function () {
    // The two refusals `MediaStore::forgetFile()` carries are about the NAME, not about orphanhood:
    // a value that was never a bare filename must not be able to reach `unlink()`.
    expect(MediaStore::forgetFile(CustomerAccounts::AVATAR_TYPE, '../../../.env'))->toBeFalse()
        ->and(MediaStore::forgetFile(CustomerAccounts::AVATAR_TYPE, 'Product/cover.webp'))->toBeFalse()
        ->and(MediaStore::forgetFile(CustomerAccounts::AVATAR_TYPE, ''))->toBeFalse()
        ->and(is_file(base_path('.env')))->toBeTrue();
});

// ── updatePassword ───────────────────────────────────────────────────────────────────────────

it('changes a password when the current one is right', function () {
    $email = Shopper::email();
    [$user, $token] = Shopper::withToken(['email' => $email, 'password' => 'the-old-password']);

    withHeaders(profileHeaders($token))->postJson('/api/updatePassword', [
        'current_password' => 'the-old-password',
        'new_password' => 'the-new-password',
        'new_password_confirmation' => 'the-new-password',
    ])->assertOk()
        // Not `assertExactJson`: M1v added a fresh `token` to this response, additively. Asserting
        // the exact shape here would make every future additive key a failure, which is the wrong
        // sensitivity — what matters is the message the screen shows and the key it may consume.
        ->assertJsonPath('message', 'Password updated successfully')
        ->assertJsonStructure(['message', 'token']);

    $hash = T::str(DB::table('users')->where('id', $user->id)->value('password'));
    expect(Hash::check('the-new-password', $hash))->toBeTrue()
        ->and(Hash::check('the-old-password', $hash))->toBeFalse();

    // …and the new password actually signs them in, which is the property a customer cares about.
    withHeaders(['Api-Code' => PROFILE_API_KEY])->postJson('/api/login', [
        'email' => $email, 'password' => 'the-new-password',
    ])->assertOk();
});

it('answers 401 for a WRONG current password, which is the status the screen turns into a sentence', function () {
    [$user, $token] = Shopper::withToken(['password' => 'the-real-password']);

    withHeaders(profileHeaders($token))->postJson('/api/updatePassword', [
        'current_password' => 'not-the-real-one',
        'new_password' => 'whatever-comes-next',
        'new_password_confirmation' => 'whatever-comes-next',
    ])->assertStatus(401);

    expect(Hash::check('the-real-password', T::str(DB::table('users')->where('id', $user->id)->value('password'))))
        ->toBeTrue();
});

it('lets a PASSWORD-LESS account set one without supplying a current password', function () {
    /*
     * A social-login account starts with `users.password` null — `social_accounts` is what proves
     * the identity. Setting a password is the only route such a customer has to ever having one,
     * and requiring the current password would make it unreachable.
     */
    [$user, $token] = Shopper::withToken();
    UserWrites::open(CustomerAccounts::PASSWORD, function () use ($user): void {
        $user->forceFill(['password' => null])->save();
    });

    withHeaders(profileHeaders($token))->postJson('/api/updatePassword', [
        'new_password' => 'a-first-password',
        'new_password_confirmation' => 'a-first-password',
    ])->assertOk();

    expect(Hash::check('a-first-password', T::str(DB::table('users')->where('id', $user->id)->value('password'))))
        ->toBeTrue();
});

it('REFUSES a short or unconfirmed new password, and changes nothing', function (array $payload) {
    [$user, $token] = Shopper::withToken(['password' => 'the-real-password']);

    withHeaders(profileHeaders($token))->postJson('/api/updatePassword', $payload + [
        'current_password' => 'the-real-password',
    ])->assertStatus(422);

    expect(Hash::check('the-real-password', T::str(DB::table('users')->where('id', $user->id)->value('password'))))
        ->toBeTrue();
})->with([
    'too short' => [['new_password' => 'short', 'new_password_confirmation' => 'short']],
    'unconfirmed' => [['new_password' => 'long-enough-one', 'new_password_confirmation' => 'a-different-one']],
]);

it('changes the TOKEN holder password, never one named in the body', function () {
    [$caller, $token] = Shopper::withToken(['password' => 'caller-password']);
    $victim = Shopper::register(['password' => 'victim-password']);

    withHeaders(profileHeaders($token))->postJson('/api/updatePassword', [
        'id' => $victim->id,
        'user_id' => $victim->id,
        'current_password' => 'caller-password',
        'new_password' => 'a-brand-new-password',
        'new_password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    expect(Hash::check('victim-password', T::str(DB::table('users')->where('id', $victim->id)->value('password'))))
        ->toBeTrue()
        ->and(Hash::check('a-brand-new-password', T::str(DB::table('users')->where('id', $caller->id)->value('password'))))
        ->toBeTrue();
});

it('leaves every OTHER column untouched when a password changes', function () {
    [$user, $token] = Shopper::withToken(['password' => 'the-old-password', 'first_name' => 'Unchanged']);
    $before = T::one(DB::table('users')->where('id', $user->id));

    withHeaders(profileHeaders($token))->postJson('/api/updatePassword', [
        'current_password' => 'the-old-password',
        'new_password' => 'the-new-password',
        'new_password_confirmation' => 'the-new-password',
    ])->assertOk();

    $after = T::one(DB::table('users')->where('id', $user->id));

    // The assertion that makes the permission narrow rather than merely intended to be.
    expect(T::str($after->first_name))->toBe(T::str($before->first_name))
        ->and(T::str($after->email))->toBe(T::str($before->email))
        ->and(T::str($after->type))->toBe(T::str($before->type))
        ->and($after->remember_token)->toBe($before->remember_token)
        ->and($after->last_login_at)->toBe($before->last_login_at)
        ->and($after->email_verified_at)->toBe($before->email_verified_at);
});
