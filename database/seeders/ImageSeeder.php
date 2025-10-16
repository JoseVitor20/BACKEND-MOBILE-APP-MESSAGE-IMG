<?php

namespace Database\Seeders;

use App\Models\Image;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ImageSeeder extends Seeder
{
    public function run()
    {
        // Limpar a tabela
        Image::truncate();

        // Dados das imagens - use URLs reais da internet para teste
        $images = [
            [
                'title' => 'Paisagem Natural',
                'description' => 'Uma bela paisagem com montanhas',
                'image_path' => 'bom_dia (1).gif',
                'image_url' => 'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=400'
            ],
            [
                'title' => 'Cidade Moderna',
                'description' => 'Vista panorâmica da cidade',
                'image_path' => 'bom_dia (2).jpeg',
                'image_url' => 'https://images.unsplash.com/photo-1477959858617-67f85cf4f1df?w=400'
            ],
            [
                'title' => 'Praia Tropical', 
                'description' => 'Praia com águas cristalinas',
                'image_path' => 'bom_dia (3).png',
                'image_url' => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=400'
            ]
        ];

        foreach ($images as $imageData) {
            Image::create($imageData);
        }

        $this->command->info('Seeder executado! ' . count($images) . ' imagens inseridas.');
    }
}