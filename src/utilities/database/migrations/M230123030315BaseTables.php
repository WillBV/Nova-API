<?php

namespace nova\utilities\database\migrations;

use nova\Nova;

class M230123030315BaseTables
{
	/**
	 * This method contains the logic to be executed when applying this migration.
	 *
	 * @return boolean
	 */
	public function up(): bool {
		// Add migration logic here.
		return true;
	}

	/**
	 * This method contains the logic to be executed when removing this migration.
	 *
	 * @return boolean
	 */
	public function down(): bool {
		// Remove & add logic to revert the migration here.
		echo "M230123030315BaseTables cannot be rolled back.\n";
		return false;
	}
}