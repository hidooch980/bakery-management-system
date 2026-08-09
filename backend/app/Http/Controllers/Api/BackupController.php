<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class BackupController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'backup' => 'required|file|max:51200'
        ]);

        $file = $request->file('backup');

        $name = now()->format('Y-m-d_H-i-s').'_'.$file->getClientOriginalName();

        $path = $file->storeAs('backups/mobile', $name);

        return response()->json([
            'success' => true,
            'file' => $path
        ]);
    }

    public function status()
    {
        return response()->json([
            'success' => true,
            'message' => 'Backup server ready'
        ]);
    }
}
