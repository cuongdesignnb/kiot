<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PcCategoryPublishingManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_operator_can_explicitly_opt_category_in_and_out_of_pc_website(): void
    {
        $admin = User::factory()->create(['role_id' => null]);

        $this->actingAs($admin)->from('/settings')->post('/settings/categories', [
            'name' => 'Website category',
            'show_on_pc_website' => true,
        ])->assertRedirect('/settings');

        $category = Category::where('name', 'Website category')->firstOrFail();
        $this->assertTrue($category->show_on_pc_website);

        $this->actingAs($admin)->from('/settings')->put('/settings/categories/'.$category->id, [
            'name' => $category->name,
            'parent_id' => null,
            'show_on_pc_website' => false,
        ])->assertRedirect('/settings');

        $this->assertFalse($category->fresh()->show_on_pc_website);
    }

    public function test_new_category_is_private_by_default(): void
    {
        $category = Category::create(['name' => 'Private by default']);

        $this->assertFalse($category->fresh()->show_on_pc_website);
    }
}
