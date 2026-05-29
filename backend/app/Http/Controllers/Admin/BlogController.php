<?php

namespace App\Http\Controllers\Admin;

use App\Models\Blog;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\BlogImage;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;

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

        $image   = $request->file('image');
        $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
        $manager = new ImageManager(new Driver());
        $img     = $manager->read($image);
        $img->toWebp()->save(public_path('/Uploads_Images/Blog/' . $NewName));

        $blog->image = $NewName;

        $blog->save();

        foreach ($request->file('many_image') as $img)
        {
            BlogImage::create([
                'blog_id' => $blog->id,

                $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.' . 'webp',
                $manager = new ImageManager(new Driver()),
                $img     = $manager->read($img),
                $img->toWebp()->save(public_path('/Uploads_Images/Blog_image/' . $NewName)),

                'image' => $NewName,
            ]);
        }

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
            $oldImage = public_path('Uploads_Images/Blog/' . $blog->image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
            $NewName = time() . '_' . date('Y-m-d_')  . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Blog/' . $NewName));

            $blog->image = $NewName;
        }

        $blog->save();


        if ($many_image = $request->file('many_image')) {
            $blogImages = BlogImage::where('blog_id', $blog->id)->get();

            foreach ($blogImages as $image) {
                $imagePath = public_path('Uploads_Images/Blog_image/' . $image->image);
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
                $image->delete();
            }
            foreach ($request->file('many_image') as $img)
            {
                BlogImage::create([
                    'blog_id' => $blog->id,

                    $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.' . 'webp',
                    $manager = new ImageManager(new Driver()),
                    $img     = $manager->read($img),
                    $img->toWebp()->save(public_path('/Uploads_Images/Blog_image/' . $NewName)),

                    'image' => $NewName,
                ]);
            }
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
