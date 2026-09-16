$u = \App\Models\User::firstOrCreate(["email" => "qa.dev@example.com"], ["name" => "QA Dev", "status" => "active", "password" => \Illuminate\Support\Facades\Hash::make("Password123!")]);
$u->password = \Illuminate\Support\Facades\Hash::make("Password123!"); $u->save();
$u->syncRoles(["Software Super Admin"]);
echo "roles=".$u->getRoleNames()->implode(",")." perms=".$u->getAllPermissions()->count().PHP_EOL;
echo "students=".\App\Models\Student::count()." vendors=".\App\Models\Vendor::count().PHP_EOL;
