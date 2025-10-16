<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Models\Image;

class GalleryController extends Controller
{
    public function getAllImages()
    {
        $images = Image::all()->map(function ($image) {
            // Gerar URL completa usando a rota que funciona
            $image->image_url = url("/images/" . $image->image_path);
            return $image;
        });
        
        return response()->json($images);
    }
}