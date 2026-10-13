<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Service;

use Derafu\Ubl\Exception\ValidationException;
use Derafu\Ubl\Service\Format\EmptyObjects;
use Derafu\Ubl\Service\JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(JsonSchemaValidator::class)]
#[UsesClass(EmptyObjects::class)]
#[UsesClass(ValidationException::class)]
final class JsonSchemaValidatorTest extends TestCase
{
    private const INVOICE = __DIR__ . '/../../fixtures/doc/json/Invoice/Invoice_001_official_example.json';

    private const ORDER_RESPONSE = __DIR__ . '/../../fixtures/doc/json/OrderResponse/OrderResponse_001_official_example.json';

    #[Test]
    public function anOfficialExampleValidatesAgainstTheSchemaOfItsOwnType(): void
    {
        (new JsonSchemaValidator())->validate($this->load(self::INVOICE));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function anInvalidDocumentThrowsWithThePointerOfEachError(): void
    {
        $data = $this->without($this->load(self::INVOICE), 'IssueDate');

        try {
            (new JsonSchemaValidator())->validate($data);
            $this->fail('A document without IssueDate must not validate.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('IssueDate', $e->getMessage());
            $this->assertStringContainsString('/Invoice', $e->getMessage());
            $this->assertNotSame([], $e->getErrors());
            $this->assertContainsOnlyString($e->getErrors());
        }
    }

    /**
     * The note writes the empty element of this example (`<cac:Item/>`) as `{"_": ""}`, and its
     * own schema does not accept that. What this package writes, `{}`, does. The official file
     * is kept as it is, so this is the test of the discrepancy.
     */
    #[Test]
    public function theOfficialOrderResponseExampleDoesNotValidateAgainstItsOwnSchema(): void
    {
        $data = $this->load(self::ORDER_RESPONSE);

        try {
            (new JsonSchemaValidator())->validate($data);
            $this->fail('The official OrderResponse example is known not to validate.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('/OrderResponse', $e->getMessage());
        }

        (new JsonSchemaValidator())->validate($this->withEmptyItems($data));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function dataWithoutExactlyOneDocumentKeyThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exactly one top-level key');

        (new JsonSchemaValidator())->validate(['_D' => 'urn:x']);
    }

    #[Test]
    public function aDocumentWithoutABundledSchemaThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No bundled JSON schema for "Bogus"');

        (new JsonSchemaValidator())->validate(['Bogus' => []]);
    }

    #[Test]
    public function dataThatCannotBeJsonThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Could not turn the data into JSON');

        (new JsonSchemaValidator())->validate(['Invoice' => ['ID' => NAN]]);
    }

    #[Test]
    public function theSameValidatorValidatesManyDocuments(): void
    {
        $validator = new JsonSchemaValidator();
        $data = $this->load(self::INVOICE);

        $validator->validate($data);
        $validator->validate($data);

        $this->assertInstanceOf(stdClass::class, EmptyObjects::of([]));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function load(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function without(array $data, string $key): array
    {
        unset($data[$key]);

        return array_map(
            fn (mixed $value): mixed => is_array($value) ? $this->without($value, $key) : $value,
            $data
        );
    }

    /**
     * What this package writes for the `Item` of the example: an empty object, not `{"_": ""}`.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function withEmptyItems(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($key === 'Item' && $value === ['_' => '']) {
                $result[$key] = [];
            } else {
                $result[$key] = is_array($value) ? $this->withEmptyItems($value) : $value;
            }
        }

        return $result;
    }
}
