<?php

namespace Database\Seeders;

use App\Models\AudienceRange;
use App\Models\Category;
use App\Models\ContentAdvisory;
use App\Models\Feature;
use App\Models\ReadingLevel;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ClassificationVocabularySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedReadingLevels();
        $this->seedAudienceRanges();
        $this->seedFeatures();
        $this->seedContentAdvisories();
        $this->seedCategories();
        $this->seedTags();
    }

    private function seedReadingLevels(): void
    {
        $levels = [
            ['pre_reader', 1, ['en' => 'Pre-reader', 'af' => 'Voorleser']],
            ['beginner', 2, ['en' => 'Beginner', 'af' => 'Beginner']],
            ['developing', 3, ['en' => 'Developing', 'af' => 'Ontwikkelend']],
            ['independent', 4, ['en' => 'Independent', 'af' => 'Onafhanklik']],
            ['advanced', 5, ['en' => 'Advanced', 'af' => 'Gevorderd']],
        ];
        foreach ($levels as [$slug, $rank, $tr]) {
            ReadingLevel::updateOrCreate(['slug' => $slug],
                ['rank' => $rank, 'translations' => $tr]);
        }
    }

    private function seedAudienceRanges(): void
    {
        $bands = [
            ['0-3', 0, 3, 1], ['4-6', 4, 6, 2], ['7-9', 7, 9, 3],
            ['10-12', 10, 12, 4], ['13-15', 13, 15, 5], ['16+', 16, 99, 6],
        ];
        foreach ($bands as [$band, $min, $max, $sort]) {
            AudienceRange::updateOrCreate(['band' => $band],
                ['min_age' => $min, 'max_age' => $max, 'sort_order' => $sort,
                 'translations' => ['en' => "Ages {$band}"]]);
        }
    }

    private function seedFeatures(): void
    {
        $digital = ['read_online', 'narrated', 'read_along', 'interactive', 'animated',
            'downloadable', 'print_friendly', 'offline_available'];
        foreach ($digital as $slug) {
            Feature::updateOrCreate(['slug' => $slug],
                ['group' => 'digital',
                 'translations' => ['en' => ucwords(str_replace('_', ' ', $slug))]]);
        }
        $access = ['selectable_text', 'screen_reader', 'human_narration', 'ai_narration',
            'captions', 'word_highlighting', 'sentence_highlighting', 'adjustable_font',
            'dyslexia_friendly', 'high_contrast', 'keyboard_navigation', 'reduced_animation'];
        foreach ($access as $slug) {
            Feature::updateOrCreate(['slug' => $slug],
                ['group' => 'accessibility',
                 'translations' => ['en' => ucwords(str_replace('_', ' ', $slug))]]);
        }
    }

    private function seedContentAdvisories(): void
    {
        $items = [
            ['none', ['en' => 'No content advisory']],
            ['mild_peril', ['en' => 'Mild peril']],
            ['sadness_loss', ['en' => 'Sadness or loss']],
            ['conflict', ['en' => 'Conflict']],
            ['scary_scenes', ['en' => 'Scary scenes']],
        ];
        foreach ($items as [$slug, $tr]) {
            ContentAdvisory::updateOrCreate(['slug' => $slug], ['translations' => $tr]);
        }
    }

    private function seedCategories(): void
    {
        // Parent "Children's Books" with children, plus the flat top-level catalogue.
        $childrensBooks = Category::updateOrCreate(['slug' => 'childrens-books'],
            ['group' => 'top', 'status' => 'active', 'sort_order' => 1,
             'translations' => ['en' => "Children's Books", 'af' => 'Kinderboeke']]);

        $top = [
            ['childrens-fiction', "Children's Fiction", 'Kinderfiksie', $childrensBooks->id],
            ['childrens-nonfiction', "Children's Nonfiction", 'Kinder-niefiksie', $childrensBooks->id],
            ['picture-books', 'Picture Books', 'Prenteboeke', $childrensBooks->id],
            ['early-readers', 'Early Readers', 'Vroeë Lesers', $childrensBooks->id],
            ['young-adult', 'Young Adult', 'Jong Volwassenes', null],
            ['education', 'Education', 'Onderwys', null],
            ['language-learning', 'Language Learning', 'Taalleer', null],
            ['activity-books', 'Activity Books', 'Aktiwiteitsboeke', null],
            ['workbooks', 'Workbooks', 'Werkboeke', null],
            ['textbooks', 'Textbooks', 'Handboeke', null],
            ['comics-graphic', 'Comics and Graphic Stories', 'Strokiesprente', null],
            ['fiction', 'Fiction', 'Fiksie', null],
            ['nonfiction', 'Nonfiction', 'Niefiksie', null],
            ['reference', 'Reference', 'Verwysing', null],
        ];
        $sort = 2;
        foreach ($top as [$slug, $en, $af, $parent]) {
            Category::updateOrCreate(['slug' => $slug],
                ['parent_id' => $parent, 'group' => 'top', 'status' => 'active',
                 'sort_order' => $sort++, 'translations' => ['en' => $en, 'af' => $af]]);
        }
    }

    private function seedTags(): void
    {
        $groups = [
            'themes_and_values' => ['Friendship', 'Family', 'Kindness', 'Confidence', 'Courage',
                'Teamwork', 'Honesty', 'Responsibility', 'Empathy', 'Perseverance', 'Curiosity',
                'Problem-solving', 'Imagination', 'Independence', 'Respect'],
            'topics' => ['Animals', 'Nature', 'School', 'Sport', 'Music', 'Art', 'Food', 'Travel',
                'Community', 'Environment', 'Space', 'Technology', 'History', 'Health', 'Emotions'],
            'educational' => ['Vocabulary Building', 'Phonics', 'Reading Practice', 'Listening Practice',
                'Pronunciation', 'Spelling', 'Comprehension', 'Language Learning', 'Numeracy',
                'Classroom Activity', 'Teacher Resource'],
            'cultural' => ['South African Stories', 'African Stories', 'Folklore', 'Local Languages',
                'Cultural Heritage', 'Traditional Stories', 'Community Life'],
            'experience' => ['Bedtime', 'Read Aloud', 'Quick Read', 'Family Reading',
                'Classroom Reading', 'Independent Reading', 'Group Reading', 'Interactive Learning'],
        ];

        // A few useful aliases so synonym mapping works out of the box.
        $aliases = [
            'friendship' => ['friends', 'companionship'],
            'nature' => ['outdoors', 'wildlife'],
            'vocabulary-building' => ['vocabulary', 'vocab'],
            'animals' => ['animal'],
        ];

        foreach ($groups as $group => $names) {
            foreach ($names as $name) {
                $slug = Str::slug($name);
                $tag = Tag::updateOrCreate(['slug' => $slug],
                    ['canonical_name' => $name, 'group' => $group, 'status' => 'active']);
                TagTranslation::updateOrCreate(['tag_id' => $tag->id, 'language_code' => 'en'],
                    ['label' => $name]);
                foreach ($aliases[$slug] ?? [] as $alias) {
                    TagAlias::updateOrCreate(['tag_id' => $tag->id, 'alias' => $alias]);
                }
            }
        }
    }
}
