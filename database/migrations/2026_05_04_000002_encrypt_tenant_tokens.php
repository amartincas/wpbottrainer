<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, increase the column sizes to accommodate encrypted data (only if needed)
        try {
            Schema::table('tenants', function (Blueprint $table) {
                $table->text('wa_access_token')->nullable()->change();
                $table->text('wa_verify_token')->nullable()->change();
            });
        } catch (\Exception $e) {
            // Column sizes might already be changed, continue
        }

        // Now encrypt the existing plain-text values
        try {
            $tenants = DB::table('tenants')->get();

            foreach ($tenants as $tenant) {
                $updates = [];

                // Encrypt wa_access_token if it exists and isn't already encrypted
                if (!empty($tenant->wa_access_token) && !$this->isEncrypted($tenant->wa_access_token)) {
                    try {
                        $updates['wa_access_token'] = Crypt::encryptString($tenant->wa_access_token);
                    } catch (\Exception $e) {
                        // Skip this field if encryption fails
                    }
                }

                // Encrypt wa_verify_token if it exists and isn't already encrypted
                if (!empty($tenant->wa_verify_token) && !$this->isEncrypted($tenant->wa_verify_token)) {
                    try {
                        $updates['wa_verify_token'] = Crypt::encryptString($tenant->wa_verify_token);
                    } catch (\Exception $e) {
                        // Skip this field if encryption fails
                    }
                }

                // Update only if there are changes
                if (!empty($updates)) {
                    DB::table('tenants')->where('id', $tenant->id)->update($updates);
                }
            }
        } catch (\Exception $e) {
            // If anything fails, just log and continue
            \Illuminate\Support\Facades\Log::error('Token encryption migration error: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            // Decrypt the tokens back to plain text
            $tenants = DB::table('tenants')->get();

            foreach ($tenants as $tenant) {
                $updates = [];

                // Decrypt wa_access_token if it's encrypted
                if (!empty($tenant->wa_access_token) && $this->isEncrypted($tenant->wa_access_token)) {
                    try {
                        $updates['wa_access_token'] = Crypt::decryptString($tenant->wa_access_token);
                    } catch (\Exception $e) {
                        // Skip if decryption fails
                        continue;
                    }
                }

                // Decrypt wa_verify_token if it's encrypted
                if (!empty($tenant->wa_verify_token) && $this->isEncrypted($tenant->wa_verify_token)) {
                    try {
                        $updates['wa_verify_token'] = Crypt::decryptString($tenant->wa_verify_token);
                    } catch (\Exception $e) {
                        // Skip if decryption fails
                        continue;
                    }
                }

                // Update only if there are changes
                if (!empty($updates)) {
                    DB::table('tenants')->where('id', $tenant->id)->update($updates);
                }
            }

            // Revert column sizes
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('wa_access_token')->nullable()->change();
                $table->string('wa_verify_token')->nullable()->change();
            });
        } catch (\Exception $e) {
            // Ignore errors on rollback
        }
    }

    /**
     * Check if a string is encrypted by Laravel
     */
    private function isEncrypted(string $payload): bool
    {
        if (strlen($payload) < 4 || substr($payload, 0, 4) !== 'base64:') {
            return false;
        }

        try {
            $decrypted = @unserialize(base64_decode(substr($payload, 7)));
            return ($decrypted !== false);
        } catch (\Exception $e) {
            return false;
        }
    }
};
