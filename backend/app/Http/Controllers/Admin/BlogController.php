<?php

namespace App\Http\Controllers\Admin;

use App\Models\Blog;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\BlogImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BlogController extends Controller
{
    public function index()
    {
        $blog = Blog::all();
        return view('Dashboard.blog.index' , compact('blog'));
    }

    public function create()
    {
        return view('Dashboard.blog.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title.en'     => 'required|min:2|max:255',
            'title.ar'     => 'required|min:2|max:255',
            'image'        => 'required|image|mimes:png,jpg,webp,gif|max:5120',
            'many_image'   => 'required',
            'many_image.*' => 'image|image|mimes:png,jpg,webp,gif|max:5120',
            'text.en'      => 'required|min:2|string',
            'text.ar'      => 'required|min:2|string',
        ]);

        $blog = new Blog;

        $blog->translateOrNew('ar')->title = $request['title']['ar'];
        $blog->translateOrNew('en')->title = $request['title']['en'];
        $blog->translateOrNew('ar')->text  = $request['text']['ar'];
        $blog->translateOrNew('en')->text  = $request['text']['en'];

        // Blog cover: max 1200x800, WebP q85.
        $blog->image = $this->processImageOrFail($request->file('image'), 'Blog', [
            'max_width'  => 1200,
            'max_height' => 800,
            'quality'    => 85,
        ]);

        // Process every gallery file to a filename FIRST (file writes are not
        // covered by the DB transaction below). Skip (and log) any that fail.
        $galleryNames = [];
        foreach ($request->file('many_image') as $img)
        {
            $name = $this->tryProcessImage($img, 'Blog_image', [
                'max_width'  => 1200,
                'max_height' => 800,
                'quality'    => 85,
            ]);
            if ($name === null) {
                continue;
            }
            $galleryNames[] = $name;
        }

        // Blog row + its gallery rows commit or roll back together.
        DB::transaction(function () use ($blog, $galleryNames) {
            $blog->save();

            foreach ($galleryNames as $name) {
                BlogImage::create([
                    'blog_id' => $blog->id,
                    'image'   => $name,
                ]);
            }
        });

        Cache::forget('AllBlog');

        return redirect(route('blog.index'))->with('success' , trans('messages.add'));
    }

    public function show(Blog $blog)
    {
        $blog_image = BlogImage::where('blog_id' , $blog->id)->get();
        return view('Dashboard.blog.show' , compact('blog' , 'blog_image'));
    }

    public function edit(Blog $blog)
    {
        return view('Dashboard.blog.edit' , compact('blog'));
    }

    public function update(Request $request, Blog $blog)
    {
        $request->validate([
            'title.en'     => 'required|min:2|max:255',
            'title.ar'     => 'required|min:2|max:255',
            'image'        => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
            'many_image'   => 'nullable',
            'many_image.*' => 'image|image|mimes:png,jpg,webp,gif|max:5120',
            'text.en'      => 'required|min:2|string',
            'text.ar'      => 'required|min:2|string',
        ]);

        $blog->translateOrNew('ar')->title = $request['title']['ar'];
        $blog->translateOrNew('en')->title = $request['title']['en'];
        $blog->translateOrNew('ar')->text  = $request['text']['ar'];
        $blog->translateOrNew('en')->text  = $request['text']['en'];

        if ($image = $request->file('image')) {
            // Blog cover: max 1200x800, WebP q85. Process new first, delete old
            // only on success (HandlesImageUploads).
            $blog->image = $this->processImageOrFail($image, 'Blog', [
                'max_width'  => 1200,
                'max_height' => 800,
                'quality'    => 85,
            ], $blog->image);
        }

        // Process ALL new gallery images first (file writes, outside the txn);
        // only swap out the old set once we have at least one successful new
        // image (never delete-then-fail).
        $newNames = [];
        if ($many_image = $request->file('many_image')) {
            foreach ($many_image as $img) {
                $name = $this->tryProcessImage($img, 'Blog_image', [
                    'max_width'  => 1200,
                    'max_height' => 800,
                    'quality'    => 85,
                ]);
                if ($name !== null) {
                    $newNames[] = $name;
                }
            }
        }

        // Blog fields + (optional) gallery replacement commit atomically. Old
        // gallery FILES are unlinked only AFTER the txn commits, so a rollback
        // never orphans DB rows whose files were already deleted.
        $oldGalleryFiles = [];
        DB::transaction(function () use ($blog, $newNames, &$oldGalleryFiles) {
            $blog->save();

            if (! empty($newNames)) {
                foreach (BlogImage::where('blog_id', $blog->id)->get() as $old) {
                    $oldGalleryFiles[] = $old->image;
                    $old->delete();
                }
                foreach ($newNames as $name) {
                    BlogImage::create([
                        'blog_id' => $blog->id,
                        'image'   => $name,
                    ]);
                }
            }
        });

        foreach ($oldGalleryFiles as $file) {
            $this->deleteUploadedImage('Blog_image', $file);
        }

        Cache::forget('AllBlog');

        return redirect(route('blog.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(Blog $blog)
    {
        $blogImages = BlogImage::where('blog_id', $blog->id)->get();

        foreach ($blogImages as $image) {
            $imagePath = public_path('Uploads_Images/Blog_image/' . $image->image);
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
            $image->delete();
        }

        $oldImage = public_path('Uploads_Images/Blog/' . $blog->image);
        if (file_exists($oldImage))
        {
            unlink($oldImage);
        }
        $blog->delete();

        Cache::forget('AllBlog');

        return back()->with('success' , trans('messages.delete'));
    }
}
