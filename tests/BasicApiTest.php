<?php

/**
 * BasicApiTest.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2019 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\Tests;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

final class BasicApiTest extends DBTestCase
{
    use DatabaseTransactions;

    public function testListDevices(): void
    {
        /** @var User $user */
        $user = User::factory()->admin()->create();
        $token = ApiToken::generateToken($user);
        $device = Device::factory()->create();

        $this->json('GET', '/api/v0/devices', [], ['X-Auth-Token' => $token->token_hash])
            ->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'devices' => [$device->toArray()],
                'count' => 1,
            ]);
    }

    public function testInventoryFilteringAndFallback(): void
    {
        /** @var User $user */
        $user = User::factory()->admin()->create();
        $token = ApiToken::generateToken($user);

        $device = Device::factory()->create([
            'sysName' => 'inventory-device',
        ]);

        DB::table('entPhysical')->insert([
            [
                'device_id' => $device->device_id,
                'entPhysicalIndex' => 1,
                'entPhysicalDescr' => 'Root inventory component',
                'entPhysicalContainedIn' => '0',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => 'Root chassis',
                'entPhysicalSerialNum' => 'ROOT-123',
            ],
            [
                'device_id' => $device->device_id,
                'entPhysicalIndex' => 2,
                'entPhysicalDescr' => 'Child inventory component',
                'entPhysicalContainedIn' => '1',
                'entPhysicalClass' => 'module',
                'entPhysicalName' => 'Child module',
                'entPhysicalSerialNum' => 'CHILD-456',
            ],
        ]);

        $headers = ['X-Auth-Token' => $token->token_hash];

        $deviceResponse = $this->json('GET', "/api/v0/inventory/{$device->device_id}", [], $headers)
            ->assertStatus(200)
            ->assertJsonCount(1, 'inventory')
            ->assertJsonPath('inventory.0.entPhysicalName', 'Root chassis')
            ->assertJsonPath('inventory.0.entPhysicalContainedIn', '0');

        $this->json('GET', "/api/v0/inventory/{$device->device_id}", ['entPhysicalContainedIn' => '1'], $headers)
            ->assertStatus(200)
            ->assertJsonCount(1, 'inventory')
            ->assertJsonPath('inventory.0.entPhysicalName', 'Child module')
            ->assertJsonPath('inventory.0.entPhysicalContainedIn', '1');

        $fallbackDevice = Device::factory()->create([
            'sysName' => 'fallback-device',
            'version' => '1.0',
            'serial' => 'FB-123',
        ]);

        $fallbackResponse = $this->json('GET', "/api/v0/inventory/{$fallbackDevice->device_id}", [], $headers)
            ->assertStatus(200)
            ->assertJsonCount(1, 'inventory')
            ->assertJsonPath('inventory.0.entPhysicalName', $fallbackDevice->sysName)
            ->assertJsonPath('inventory.0.entPhysicalSerialNum', $fallbackDevice->serial)
            ->assertJsonPath('inventory.0.entPhysicalContainedIn', '0')
            ->assertJsonPath('inventory.0.entPhysicalClass', '')
            ->assertJsonPath('inventory.0.entPhysicalParentRelPos', '-1')
            ->assertJsonPath('inventory.0.entPhysicalIsFRU', 'false')
            ->assertJsonPath('inventory.0.ifIndex', null)
            ->assertJsonPath('inventory.0.device_id', $fallbackDevice->device_id);

        $this->assertEqualsCanonicalizing(
            array_keys($deviceResponse->json('inventory.0')),
            array_keys($fallbackResponse->json('inventory.0')),
            'Fallback inventory response should match entPhysical column keys'
        );
    }
}
