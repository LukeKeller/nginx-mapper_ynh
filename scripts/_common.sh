#!/bin/bash

#=================================================
# COMMON VARIABLES AND HELPERS
#=================================================

# PHP version used by the FPM pool and the CLI refresh cron.
phpversion="8.2"

# Where the GeoIP database lives. $data_dir is provided by YunoHost's
# data_dir resource (e.g. /home/yunohost.app/$app) and is writable by $app,
# so both the web "refresh" button and the monthly cron can replace the file.
mmdb_path="$data_dir/dbip-city-lite.mmdb"

# The app reads every site's nginx access log under here. To do that the app
# user must belong to the 'adm' group (nginx logs are root:adm, mode 640).
grant_log_access() {
    usermod --append --groups adm "$app"
}

# Fetch the latest DB-IP City Lite database now, as the app user, reusing the
# exact same code path the web button and cron use. Best-effort: never abort
# the calling script if the download fails (e.g. no network at install time).
refresh_mmdb_now() {
    ynh_exec_as "$app" php"$phpversion" "$install_dir/index.php" refresh \
        || ynh_print_warn --message="Could not download the GeoIP database now; use the 'Refresh' button in the app or wait for the monthly cron."
}
