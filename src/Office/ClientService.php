<?php

declare(strict_types=1);

namespace App\Office;

use App\Http\Support\Input;
use App\Http\Support\Pagination;
use Ramsey\Uuid\Uuid;

/**
 * Business rules + validation for `clients`. Controllers hand it an Input and
 * get back the API representation; they never touch the repository directly.
 */
final class ClientService
{
    private const TYPES = ['exporter', 'importer'];

    public function __construct(private readonly ClientRepository $clients)
    {
    }

    /** @return array<string,mixed> */
    public function create(Input $in): array
    {
        $data = [
            'name'          => $in->requiredString('name', 200),
            'type'          => $in->requiredEnum('type', self::TYPES),
            'rc_number'     => $in->optionalString('rc_number', 50),
            'address'       => $in->requiredString('address', 5000),
            'contact_name'  => $in->requiredString('contact_name', 150),
            'contact_phone' => $in->requiredString('contact_phone', 20),
            'contact_email' => $in->requiredEmail('contact_email'),
        ];

        return Representation::client($this->clients->create(Uuid::uuid4()->toString(), $data));
    }

    /** @return array<string,mixed> */
    public function update(string $uuid, Input $in): array
    {
        $id = $this->clients->findIdByUuid($uuid) ?? throw new NotFoundException('Client');

        $data = [];
        if ($in->has('name'))          { $data['name'] = $in->requiredString('name', 200); }
        if ($in->has('type'))          { $data['type'] = $in->requiredEnum('type', self::TYPES); }
        if ($in->has('rc_number'))     { $data['rc_number'] = $in->optionalString('rc_number', 50); }
        if ($in->has('address'))       { $data['address'] = $in->requiredString('address', 5000); }
        if ($in->has('contact_name'))  { $data['contact_name'] = $in->requiredString('contact_name', 150); }
        if ($in->has('contact_phone')) { $data['contact_phone'] = $in->requiredString('contact_phone', 20); }
        if ($in->has('contact_email')) { $data['contact_email'] = $in->requiredEmail('contact_email'); }

        return Representation::client($this->clients->update($id, $data));
    }

    /** @return array<string,mixed> */
    public function get(string $uuid): array
    {
        $row = $this->clients->findByUuid($uuid) ?? throw new NotFoundException('Client');

        return Representation::client($row);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function list(Pagination $page, array $filters): array
    {
        $result = $this->clients->paginate($page->perPage, $page->offset(), $filters);

        return $page->envelope(
            array_map([Representation::class, 'client'], $result['rows']),
            $result['total'],
        );
    }
}
