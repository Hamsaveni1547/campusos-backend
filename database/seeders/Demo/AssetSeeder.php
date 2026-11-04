<?php

namespace Database\Seeders\Demo;

use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\MaintenanceRequest;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Institution\Models\Room;
use Database\Seeders\Support\CampusSeeder;
use Illuminate\Support\Carbon;

/**
 * Equipment in labs and lecture halls, maintenance requests in every state and a few room bookings.
 */
class AssetSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        if (Asset::query()->exists()) {
            return;
        }

        $service = app(AssetService::class);
        $office = $this->user('office@'.self::DOMAIN);
        $n = 0;

        $this->as($office, function () use (&$n): void {
            foreach (Room::query()->orderBy('code')->get() as $room) {
                $items = $room->type->value === 'laboratory'
                    ? [['Desktop computer', 'Computers', 'Dell', 'OptiPlex 7010', 52000, 6], ['UPS 2 kVA', 'Power', 'APC', 'SRV2KI', 38000, 1], ['Air conditioner 2 ton', 'Air conditioning', 'Voltas', '243V DZW', 54000, 1]]
                    : [['Projector', 'Projectors', 'Epson', 'EB-X51', 38000, 1], ['Ceiling fan', 'Electrical', 'Crompton', 'HS Plus', 2100, 2]];

                foreach ($items as [$name, $category, $make, $model, $cost, $count]) {
                    foreach (range(1, $count) as $i) {
                        $n++;
                        $purchased = Carbon::create(2021 + $n % 4, 1 + $n % 12, 10);
                        Asset::query()->create([
                            'tag' => sprintf('BIT-%s-%03d', strtoupper(substr(preg_replace('/[^A-Z]/', '', strtoupper($category)) ?: 'EQ', 0, 3)), $n),
                            'name' => $name, 'category' => $category, 'room_id' => $room->id, 'department_id' => $room->department_id,
                            'make' => $make, 'model' => $model, 'serial_no' => strtoupper(substr(md5($room->code.$name.$i), 0, 10)),
                            'purchased_on' => $purchased->toDateString(), 'cost' => $cost, 'vendor' => ['Sri Sai Computers', 'Bangalore Electricals', 'Coolair Services'][$n % 3],
                            'warranty_until' => $n % 9 === 0 ? now()->addDays(20 + $n % 30)->toDateString() : $purchased->copy()->addYears(3)->toDateString(),
                            'status' => $n % 23 === 0 ? 'condemned' : 'working',
                        ]);
                    }
                }
            }
        });

        // Faults raised by staff, handled by the office.
        $assets = Asset::query()->where('status', 'working')->orderBy('id')->get()->values();
        foreach ([
            ['hod.cse', 0, 'PC does not boot', 'high', 'resolved', 'Ravi (IT support)', 'Replaced the SMPS', 1800],
            ['kavya.shetty', 3, 'Monitor flickering', 'medium', 'in_progress', 'Sri Sai Computers', null, null],
            ['raghavendra.kulkarni', 7, 'AC not cooling', 'high', 'open', null, null, null],
            ['nandini.rao', 12, 'Projector shows a pink tint', 'low', 'closed', 'Epson service centre', 'VGA cable replaced', 450],
            ['meghana.iyer', 20, 'Fan making noise', 'low', 'open', null, null, null],
        ] as [$login, $index, $title, $priority, $status, $assignee, $resolution, $cost]) {
            $asset = $assets[$index % $assets->count()];
            $request = $this->as($this->user($login.'@'.self::DOMAIN), function () use ($asset, $title, $priority): MaintenanceRequest {
                $request = MaintenanceRequest::query()->create(['asset_id' => $asset->id, 'room_id' => $asset->room_id, 'title' => $title, 'priority' => $priority]);
                $request->forceFill(['status' => 'open'])->save();

                return $request;
            });

            $this->as($office, function () use ($service, $request, $status, $assignee, $resolution, $cost): void {
                if (in_array($status, ['in_progress', 'resolved', 'closed'], true)) {
                    $service->updateRequest($request->refresh(), 'in_progress', ['assigned_to' => $assignee]);
                }
                if (in_array($status, ['resolved', 'closed'], true)) {
                    $service->updateRequest($request->refresh(), 'resolved', ['resolution' => $resolution, 'cost' => (float) $cost]);
                }
                if ($status === 'closed') {
                    $service->updateRequest($request->refresh(), 'closed', []);
                }
            });
        }

        $lab = Room::query()->where('code', 'CS-LAB1')->firstOrFail();
        $hall = Room::query()->where('code', 'SEM-HALL')->firstOrFail();
        $this->as($this->user('kavya.shetty@'.self::DOMAIN), fn () => $service->book($lab, now()->addDays(2)->startOfDay(), '16:00', '17:30', 'DBMS mini-project review'));
        $this->as($this->user('hod.cse@'.self::DOMAIN), fn () => $service->book($hall, now()->addDays(6)->startOfDay(), '10:00', '13:00', 'Guest lecture: Generative AI in industry'));
    }
}
