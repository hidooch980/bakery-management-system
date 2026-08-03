<?php

return [
    /*
     * Where the nightly database backup is sent — a comma-separated list.
     *
     * Off-machine is the whole point: a copy that only ever sits on the
     * same disk is lost to the same accident as the original. More than one
     * address is better still, since an inbox can also fail or fill up.
     */
    'mail_to' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BACKUP_MAIL_TO', ''))
    ))),
];
