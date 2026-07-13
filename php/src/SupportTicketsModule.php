<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\SupportTickets\Domain\TicketRepository;
use Tds\Panel\Contract\AbstractModule;
use Tds\Panel\Contract\Mailer;
use Tds\Panel\Contract\PermissionDef;
use Tds\Panel\Contract\UserContext;

/**
 * Backend Module for the support-ticket system (checkpoint-1 surface: customer
 * list/get/create/comment + widget summary; admin list/get/comment/triage-update).
 *
 * Auth comes entirely from the core {@see UserContext} — customer routes require
 * `tickets:read`/`tickets:write` (admins bypass) and scope by the active company;
 * admin routes require `isAdmin`. Email goes through the core {@see Mailer} via
 * {@see Notifier}. Data via the core shared PDO.
 *
 * Still to port (later checkpoints): status registry CRUD, attachments, richer
 * notifications + customer directory, IMAP + contact-form ingest.
 */
final class SupportTicketsModule extends AbstractModule
{
    public function id(): string
    {
        return 'support-tickets';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('tickets:read', 'Tickets ansehen', 'support-tickets'),
            new PermissionDef('tickets:write', 'Tickets erstellen & beantworten', 'support-tickets'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        if ($c !== null && !$c->has(TicketRepository::class)) {
            $c->set(TicketRepository::class, static fn ($c) => new TicketRepository($c->get(PDO::class)));
            $c->set(Notifier::class, static fn ($c) => new Notifier(
                $c->get(Mailer::class),
                (string) (getenv('TICKET_ADMIN_EMAIL') ?: ''),
            ));
        }

        // --- Customer (portal) routes ------------------------------------------
        $app->get('/tickets/summary', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $open = $user->isAdmin() && $user->activeCompanyId() === null
                ? $repo->openCountAll()
                : $repo->openCountForCustomer((int) $user->activeCompanyId());
            return self::json($res, ['open' => $open]);
        });

        $app->get('/tickets', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:read', $res)) !== null) {
                return $deny;
            }
            $cid = $user->activeCompanyId();
            $tickets = $cid === null ? [] : $c->get(TicketRepository::class)->listForCustomer($cid);
            return self::json($res, ['tickets' => $tickets]);
        });

        $app->post('/tickets', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:write', $res)) !== null) {
                return $deny;
            }
            $cid = $user->activeCompanyId();
            if ($cid === null) {
                return self::json($res, ['error' => 'No active company'], 400);
            }
            $body = (array) $req->getParsedBody();
            $subject = trim((string) ($body['subject'] ?? ''));
            $description = trim((string) ($body['description'] ?? ''));
            if ($subject === '' || $description === '') {
                return self::json($res, ['error' => 'subject and description are required'], 422);
            }
            $repo = $c->get(TicketRepository::class);
            $id = $repo->createForCustomer(
                $cid,
                $user->userId(),
                $subject,
                $description,
                self::enum($body['type'] ?? 'question', ['question', 'bug', 'feature', 'other'], 'question'),
                self::enum($body['priority'] ?? 'normal', ['low', 'normal', 'high', 'urgent'], 'normal'),
            );
            $c->get(Notifier::class)->newCustomerTicket($id, $subject);
            return self::json($res, ['id' => $id], 201);
        });

        $app->get('/tickets/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $ticket = $repo->find((int) $args['id']);
            if ($ticket === null || (int) $ticket['customer_id'] !== $user->activeCompanyId()) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $ticket['comments'] = $repo->comments((int) $ticket['id'], includeInternal: false);
            return self::json($res, $ticket);
        });

        $app->post('/tickets/{id:[0-9]+}/comments', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            $ticket = $repo->find($id);
            if ($ticket === null || (int) $ticket['customer_id'] !== $user->activeCompanyId()) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $text = trim((string) (((array) $req->getParsedBody())['body'] ?? ''));
            if ($text === '') {
                return self::json($res, ['error' => 'body is required'], 422);
            }
            $repo->addComment($id, 'customer', $user->userId(), $text, isInternal: false);
            $repo->clearCustomerAction($id);
            $c->get(Notifier::class)->newCustomerComment($id, (string) $ticket['subject']);
            return self::json($res, ['ok' => true], 201);
        });

        // --- Admin routes ------------------------------------------------------
        $app->get('/admin/tickets', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['tickets' => $c->get(TicketRepository::class)->adminList()]);
        });

        $app->get('/admin/tickets/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $ticket = $repo->find((int) $args['id']);
            if ($ticket === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $ticket['comments'] = $repo->comments((int) $ticket['id'], includeInternal: true);
            return self::json($res, $ticket);
        });

        $app->post('/admin/tickets/{id:[0-9]+}/comments', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::requireAdmin($user, $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            $ticket = $repo->find($id);
            if ($ticket === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            $text = trim((string) ($body['body'] ?? ''));
            if ($text === '') {
                return self::json($res, ['error' => 'body is required'], 422);
            }
            $internal = (bool) ($body['is_internal'] ?? false);
            $repo->addComment($id, 'owner', $user->userId(), $text, $internal);
            if (!$internal) {
                $c->get(Notifier::class)->newOwnerComment($id, (string) $ticket['subject'], $ticket['from_email'] ?? null);
            }
            return self::json($res, ['ok' => true], 201);
        });

        $app->patch('/admin/tickets/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            if ($repo->find($id) === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            $fields = [];
            foreach (['status_id', 'assignee_user_id'] as $k) {
                if (array_key_exists($k, $body)) {
                    $fields[$k] = $body[$k] === null ? null : (int) $body[$k];
                }
            }
            if (isset($body['priority'])) {
                $fields['priority'] = self::enum($body['priority'], ['low', 'normal', 'high', 'urgent'], 'normal');
            }
            if (isset($body['type'])) {
                $fields['type'] = self::enum($body['type'], ['question', 'bug', 'feature', 'other', 'contact'], 'question');
            }
            if (array_key_exists('customer_action_required', $body)) {
                $fields['customer_action_required'] = (bool) $body['customer_action_required'] ? 1 : 0;
            }
            if (array_key_exists('customer_action_note', $body)) {
                $fields['customer_action_note'] = $body['customer_action_note'] === null ? null : (string) $body['customer_action_note'];
            }
            $repo->updateAdmin($id, $fields);
            return self::json($res, ['ok' => true]);
        });

        // Status registry (admin CRUD).
        $app->get('/admin/ticket-statuses', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['statuses' => $c->get(TicketRepository::class)->statuses()]);
        });

        $app->post('/admin/ticket-statuses', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $data = self::statusPayload((array) $req->getParsedBody());
            if (is_string($data)) {
                return self::json($res, ['error' => $data], 422);
            }
            return self::json($res, ['id' => $c->get(TicketRepository::class)->createStatus($data)], 201);
        });

        $app->patch('/admin/ticket-statuses/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            if (!$repo->statusExists($id)) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $data = self::statusPayload((array) $req->getParsedBody());
            if (is_string($data)) {
                return self::json($res, ['error' => $data], 422);
            }
            $repo->updateStatus($id, $data);
            return self::json($res, ['ok' => true]);
        });

        $app->delete('/admin/ticket-statuses/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            if (!$repo->statusExists($id)) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            if ($repo->statusInUse($id)) {
                return self::json($res, ['error' => 'Status wird von Tickets verwendet und kann nicht gelöscht werden.'], 409);
            }
            if ($repo->statusCount() <= 1) {
                return self::json($res, ['error' => 'Mindestens ein Status muss bestehen bleiben.'], 409);
            }
            $repo->deleteStatus($id);
            return self::json($res, ['ok' => true]);
        });
    }

    // --- helpers ---------------------------------------------------------------

    /** 401/403 response when the principal fails the permission check, else null. */
    private static function require(UserContext $user, string $permission, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has($permission)) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function requireAdmin(UserContext $user, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->isAdmin()) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    /**
     * Validate + normalise a ticket_status payload. Returns the clean data array
     * or an error message string.
     *
     * @param array<string,mixed> $body
     * @return array{name:string,color:string,sort_order:int,visible_to_customer:bool,is_terminal:bool,is_default:bool}|string
     */
    private static function statusPayload(array $body): array|string
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return 'name is required';
        }
        $color = (string) ($body['color'] ?? 'neutral');
        if (!in_array($color, TicketRepository::STATUS_COLORS, true)) {
            return 'color must be one of: ' . implode(', ', TicketRepository::STATUS_COLORS);
        }
        return [
            'name' => $name,
            'color' => $color,
            'sort_order' => (int) ($body['sort_order'] ?? 0),
            'visible_to_customer' => (bool) ($body['visible_to_customer'] ?? true),
            'is_terminal' => (bool) ($body['is_terminal'] ?? false),
            'is_default' => (bool) ($body['is_default'] ?? false),
        ];
    }

    /** @param string[] $allowed */
    private static function enum(mixed $value, array $allowed, string $default): string
    {
        $v = is_string($value) ? $value : '';
        return in_array($v, $allowed, true) ? $v : $default;
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
