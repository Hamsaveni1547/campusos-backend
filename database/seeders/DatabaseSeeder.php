<?php

namespace Database\Seeders;

use App\Modules\Identity\Actions\Roles\SyncSystemPermissions;
use Database\Seeders\Demo\AdmissionsSeeder;
use Database\Seeders\Demo\AnnouncementSeeder;
use Database\Seeders\Demo\AssessmentSeeder;
use Database\Seeders\Demo\AssetSeeder;
use Database\Seeders\Demo\AttendanceSeeder;
use Database\Seeders\Demo\CurriculumSeeder;
use Database\Seeders\Demo\ExamSeeder;
use Database\Seeders\Demo\FeedbackSeeder;
use Database\Seeders\Demo\FeeSeeder;
use Database\Seeders\Demo\InstitutionSetupSeeder;
use Database\Seeders\Demo\LeaveSeeder;
use Database\Seeders\Demo\LibrarySeeder;
use Database\Seeders\Demo\MentoringSeeder;
use Database\Seeders\Demo\PastSemesterSeeder;
use Database\Seeders\Demo\PayrollSeeder;
use Database\Seeders\Demo\QuestionBankSeeder;
use Database\Seeders\Demo\RegistrationSeeder;
use Database\Seeders\Demo\StaffSeeder;
use Database\Seeders\Demo\StudentSeeder;
use Database\Seeders\Demo\TeachingSeeder;
use Database\Seeders\Demo\TimetableSeeder;
use Database\Seeders\Support\CampusSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Permissions and built-in roles are always seeded. Demo data (Bangalore Institute of Technology)
     * only outside production; every demo account uses the password in CampusSeeder::DEMO_PASSWORD.
     * The order matters: each seeder builds on the ones before it.
     */
    public function run(SyncSystemPermissions $syncPermissions): void
    {
        $syncPermissions->handle();

        if (app()->isProduction()) {
            return;
        }

        // Hundreds of demo accounts: a lower hashing cost keeps seeding quick (demo data only).
        config(['hashing.bcrypt.rounds' => 4]);

        $this->call([
            InstitutionSetupSeeder::class,
            StaffSeeder::class,
            CurriculumSeeder::class,
            StudentSeeder::class,
            TeachingSeeder::class,
            PastSemesterSeeder::class,
            RegistrationSeeder::class,
            TimetableSeeder::class,
            AttendanceSeeder::class,
            AssessmentSeeder::class,
            ExamSeeder::class,
            QuestionBankSeeder::class,
            AdmissionsSeeder::class,
            FeeSeeder::class,
            LeaveSeeder::class,
            PayrollSeeder::class,
            LibrarySeeder::class,
            AssetSeeder::class,
            FeedbackSeeder::class,
            MentoringSeeder::class,
            AnnouncementSeeder::class,
        ]);

        $this->command?->table(['Sign in as', 'Email'], [
            ['Institution admin', 'admin@'.CampusSeeder::DOMAIN],
            ['Principal', 'principal@'.CampusSeeder::DOMAIN],
            ['Controller of examinations', 'coe@'.CampusSeeder::DOMAIN],
            ['HOD (cse, aids, ise, mca, mba)', 'hod.cse@'.CampusSeeder::DOMAIN],
            ['Faculty', 'kavya.shetty@'.CampusSeeder::DOMAIN],
            ['Office / admissions', 'office@ and admissions@'.CampusSeeder::DOMAIN],
            ['HR / accounts (fees) / library', 'hr@, accounts@, library@'.CampusSeeder::DOMAIN],
            ['Student', 'student@'.CampusSeeder::DOMAIN],
            ['Applicant', 'applicant@'.CampusSeeder::DOMAIN],
            ['Platform admin', 'platform.admin@campusos.test'],
            ['Password (all)', CampusSeeder::DEMO_PASSWORD],
        ]);
    }
}
