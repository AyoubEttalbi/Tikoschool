<?php

namespace App\Http\Controllers;
use Spatie\Activitylog\Models\Activity;
use App\Models\User;
use App\Models\Assistant;
use App\Models\School;
use App\Models\Subject;
use App\Models\Classes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
// use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
class AssistantController extends Controller
{   
    // Helper function to get Cloudinary
    private function getCloudinary()
    {
        return new Cloudinary(
            Configuration::instance([
                'cloud' => [
                    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                    'api_key' => env('CLOUDINARY_API_KEY'),
                    'api_secret' => env('CLOUDINARY_API_SECRET'),
                ],
                'url' => [
                    'secure' => true
                ]
            ])
        );
    }
    /**
    * @param \Illuminate\Http\UploadedFile $file
    * @param string $folder
    * @param int $width
    * @param int $height
    * @return array
    */
   private function uploadToCloudinary($file, $folder = 'assistants', $width = 300, $height = 300)
   {
       $cloudinary = $this->getCloudinary();
       $uploadApi = $cloudinary->uploadApi();
       
       // Get file info
       $fileSize = $file->getSize(); // Size in bytes
       $fileExtension = $file->extension();
       
       // Set quality based on file size to optimize
       $quality = 'auto';
       
       // For large images, we can be more aggressive with compression
       if ($fileSize > 1000000) { // 1MB
           $quality = 'auto:low';
       }
       
       // Upload parameters
       $options = [
           'folder' => $folder,
           'transformation' => [
               // Resize to fit within dimensions while maintaining aspect ratio
               [
                   'width' => $width, 
                   'height' => $height, 
                   'crop' => 'fill',
                   'gravity' => 'auto', // Smart cropping
               ],
               // Quality and format optimization
               [
                   'quality' => $quality,
                   'fetch_format' => 'auto', // Auto select best format (webp when possible)
               ],
           ],
           // Add public_id for better organization (optional)
           'public_id' => 'assistant_' . time() . '_' . random_int(1000, 9999),
           // Optional: Add these for even more optimization
           'resource_type' => 'image',
           'flags' => 'lossy', // Apply lossy compression
           // Add metadata for better tracking
           'context' => 'source=laravel-app|user=assistant',
       ];
       
       // Upload to Cloudinary
       $result = $uploadApi->upload($file->getRealPath(), $options);
       
       // Return the results with all URLs and data
       return [
           'secure_url' => $result['secure_url'],
           'public_id' => $result['public_id'],
           'format' => $result['format'],
           'width' => $result['width'],
           'height' => $result['height'],
           'bytes' => $result['bytes'],
           'resource_type' => $result['resource_type'],
           'created_at' => $result['created_at'],
       ];
   }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $schools = School::all();
        $query = Assistant::query();

        // Apply search filter if search term is provided
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;

            $query->where(function ($q) use ($searchTerm) {
                // Search by assistant fields
                $q->where('first_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('last_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('phone_number', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('address', 'LIKE', "%{$searchTerm}%");

                // Search by school name (if assistant has a relationship with schools)
                $q->orWhereHas('schools', function ($schoolQuery) use ($searchTerm) {
                    $schoolQuery->where('name', 'LIKE', "%{$searchTerm}%");
                });
            });
        }

        // Fetch paginated and filtered assistants
        $assistants = $query->paginate(10)->withQueryString()->through(function ($assistant) {
            return [
                'id' => $assistant->id,
                'name' => $assistant->first_name . ' ' . $assistant->last_name,
                'phone_number' => $assistant->phone_number,
                'first_name' => $assistant->first_name,
                'last_name' => $assistant->last_name,
                'email' => $assistant->email,
                'address' => $assistant->address,
                'status' => $assistant->status,
                'salary' => $assistant->salary,
                'profile_image' => $assistant->profile_image ? $assistant->profile_image : null,
                'schools_assistant' => $assistant->schools,
            ];
        });

        return Inertia::render('Menu/AssistantsListPage', [
            'assistants' => $assistants,
            'schools' => $schools,
            'search' => $request->search,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $subjects = Subject::all();
        $classes = Classes::all();
        $schools = School::all();

        return Inertia::render('AssistantsListPage/Create', [
            'subjects' => $subjects,
            'classes' => $classes,
            'schools' => $schools,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|string|email|max:255|unique:assistants,email',
                'phone_number' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:5120', // Increased to 5MB
                'salary' => 'required|numeric|min:0',
                'status' => 'required|in:active,inactive',
                'schools' => 'required|array',
                'schools.*' => 'exists:schools,id',
            ]);

            // Handle profile image upload to Cloudinary
            if ($request->hasFile('profile_image')) {
                $uploadedFile = $request->file('profile_image');
                
                // Use our optimized upload method
                $uploadResult = $this->uploadToCloudinary($uploadedFile);
                
                // Store only the secure URL in the database
                $validatedData['profile_image'] = $uploadResult['secure_url'];
                
                // Optionally, you can store more metadata in your database
                // $validatedData['profile_image_public_id'] = $uploadResult['public_id'];
                // $validatedData['profile_image_bytes'] = $uploadResult['bytes'];
            }

            // Create the assistant record
            $assistant = Assistant::create($validatedData);

            // Sync schools with the assistant
            $assistant->schools()->sync($request->schools);

            return redirect()->back()->with('success', 'Assistant created successfully.');
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'An error occurred while creating the assistant: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    
    public function show($id)
    {
        $assistant = Assistant::with(['schools'])->find($id);
        $schools = School::all();
        $classes = Classes::all();
        $subjects = Subject::all();
    
        if (!$assistant) {
            abort(404);
        }
    
        // Find the user by email
        $user = User::where('email', $assistant->email)->first();
    
        if (!$user) {
            // If no user is found, return an empty log array
            $logs = [];
        } else {
            // Fetch the assistant's activity logs based on the user's ID
            $logs = Activity::where('causer_type', User::class) // Use User::class as the causer type
                ->where('causer_id', $user->id) // Use the user's ID as the causer ID
                ->latest()
                ->paginate(10);
        }
    
        return Inertia::render('Menu/SingleAssistantPage', [
            'assistant' => [
                'id' => $assistant->id,
                'first_name' => $assistant->first_name,
                'last_name' => $assistant->last_name,
                'email' => $assistant->email,
                'phone_number' => $assistant->phone_number,
                'address' => $assistant->address,
                'status' => $assistant->status,
                'salary' => $assistant->salary,
                'profile_image' => $assistant->profile_image ? $assistant->profile_image : null,
                'schools_assistant' => $assistant->schools,
                'created_at' => $assistant->created_at,
            ],
            'schools' => $schools,
            'subjects' => $subjects,
            'classes' => $classes,
            'logs' => $logs, // Pass the logs to the frontend
        ]);
    }
    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Assistant $assistant)
    {
        $subjects = Subject::all();
        $classes = Classes::all();
        $schools = School::all();

        return Inertia::render('Assistants/Edit', [
            'assistant' => $assistant,
            'subjects' => $subjects,
            'classes' => $classes,
            'schools' => $schools,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Assistant $assistant)
    {
        try {
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|string|email|max:255|unique:assistants,email,' . $assistant->id,
                'phone_number' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:5120', // Increased to 5MB
                'salary' => 'required|numeric|min:0',
                'status' => 'required|in:active,inactive',
                'schools' => 'array',
                'schools.*' => 'exists:schools,id',
            ]);

            // Handle profile image upload to Cloudinary
            if ($request->hasFile('profile_image')) {
                $uploadedFile = $request->file('profile_image');
                
                // Use our optimized upload method
                $uploadResult = $this->uploadToCloudinary($uploadedFile);
                
                // Store only the secure URL in the database
                $validatedData['profile_image'] = $uploadResult['secure_url'];
                
                // If you want to delete the old image, you could do that here
                if ($assistant->profile_image && $assistant->profile_image_public_id) {
                    $cloudinary = $this->getCloudinary();
                    $cloudinary->uploadApi()->destroy($assistant->profile_image_public_id);
                }
            }

            // Update the assistant record
            $assistant->update($validatedData);

            // Sync schools with the assistant
            $assistant->schools()->sync($request->schools ?? []);

            return redirect()->route('assistants.show', $assistant->id)->with('success', 'Assistant updated successfully.');
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'An error occurred while updating the assistant: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Assistant $assistant)
    {
        try {
            // if ($assistant->profile_image) {
            //     Storage::disk('public')->delete($assistant->profile_image);
            // }

            $assistant->delete();

            return redirect()->route('assistants.index')->with('success', 'Assistant deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'An error occurred while deleting the assistant: ' . $e->getMessage());
        }
    }
}