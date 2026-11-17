<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use Psr\Http\Message\ResponseInterface;

/**
 * Members used across the suite:
 *  1 admin, 2 author, 3 follower (follows 2), 4 stranger, 5 moderator (Moderators group).
 */
abstract class GlimpseTestCase extends TestCase
{

    /** Whether ianm/follow-users is on for this test class. */
    protected bool $follows = true;

    /** @var list<string> other extensions this test class needs on */
    protected array $alsoEnable = [];

    protected function setUp(): void
    {
        parent::setUp();

        $extensions = $this->follows ? ['flarum-tags', 'fof-follow-tags', 'ianm-follow-users'] : [];
        array_push($extensions, ...$this->alsoEnable);
        $extensions[] = 'ernestdefoe-glimpse';
        $this->extension(...$extensions);

        $users = [];
        foreach ([2 => 'author', 3 => 'follower', 4 => 'stranger', 5 => 'moderator'] as $id => $name) {
            $users[] = ['id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'is_email_confirmed' => 1, 'password' => 'x', 'joined_at' => Carbon::now()->subYear()];
        }

        $data = [
            User::class => $users,
            'group_user' => [['user_id' => 5, 'group_id' => Group::MODERATOR_ID]],
        ];
        if ($this->follows) {
            $data['user_followers'] = [['user_id' => 3, 'followed_user_id' => 2, 'subscription' => 'follow']];
        }

        $this->prepareDatabase($data);
        // The flood guard has its own test; everywhere else it would only slow the suite.
        $this->setting('ernestdefoe-glimpse.flood_seconds', 0);
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function upload(int $as, string $bytes, string $name = 'photo.jpg', string $claimed = 'image/jpeg'): ResponseInterface
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($bytes);
        $stream->rewind();

        return $this->send(
            $this->request('POST', '/api/glimpse-media', ['authenticatedAs' => $as])
                ->withUploadedFiles(['file' => new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $name, $claimed)])
        );
    }

    protected function uploadId(int $as, ?string $bytes = null): string
    {
        $response = $this->upload($as, $bytes ?? $this->jpeg());
        $this->assertSame(201, $response->getStatusCode(), substr((string) $response->getBody(), 0, 1500));

        return (string) $this->json($response)['data']['id'];
    }

    protected function jpeg(int $w = 60, int $h = 100): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 90));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../fixtures/'.$name);
    }

    /**
     * A live story written straight to the database, for tests that must change settings or
     * permissions before the first request (the app caches both once booted).
     *
     * @param array<string, mixed> $overrides
     */
    protected function insert(int $userId, array $overrides = []): int
    {
        $now = Carbon::now();

        return (int) $this->database()->table('glimpse_stories')->insertGetId($overrides + [
            'user_id' => $userId,
            'kind' => 'text',
            'text' => 'Hello',
            'background' => 0,
            'audience' => 'everyone',
            'status' => 'live',
            'created_at' => $now,
            'approved_at' => $now,
            'expires_at' => $now->copy()->addDay(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function post(int $as, array $attributes, ?string $mediaId = null): ResponseInterface
    {
        $data = ['type' => 'glimpse-stories', 'attributes' => $attributes];
        if ($mediaId !== null) {
            $data['relationships'] = ['media' => ['data' => ['type' => 'glimpse-media', 'id' => $mediaId]]];
        }

        return $this->send($this->request('POST', '/api/glimpse-stories', ['authenticatedAs' => $as, 'json' => ['data' => $data]]));
    }

    /** A text story by $as, returning its id. */
    protected function text(int $as, array $attributes = []): int
    {
        $response = $this->post($as, ['kind' => 'text', 'text' => 'Hello', 'background' => 1] + $attributes);
        $this->assertSame(201, $response->getStatusCode(), substr((string) $response->getBody(), 0, 1500));

        return (int) $this->json($response)['data']['id'];
    }

    /** @param array<string, mixed> $query */
    protected function get(string $path, ?int $as = null, array $query = []): ResponseInterface
    {
        $request = $this->request('GET', $path, $as ? ['authenticatedAs' => $as] : []);

        return $this->send($query ? $request->withQueryParams($query) : $request);
    }

    /**
     * @param array<string, mixed> $filter
     * @return list<int>
     */
    protected function ids(?int $as, array $filter = ['live' => '1']): array
    {
        $response = $this->get('/api/glimpse-stories', $as, ['filter' => $filter, 'page' => ['limit' => 100]]);
        $this->assertSame(200, $response->getStatusCode(), substr((string) $response->getBody(), 0, 1500));

        return array_map(fn (array $row): int => (int) $row['id'], $this->json($response)['data']);
    }

    /** @param array<string, mixed> $body */
    protected function postJson(string $path, int $as, array $body): ResponseInterface
    {
        return $this->send($this->request('POST', $path, ['authenticatedAs' => $as, 'json' => $body]));
    }
}
