import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, Controller } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router, usePage } from "@inertiajs/react";
import Select from "react-select";
import { useState } from "react";
import Register from "@/Pages/Auth/Register";
import { ChevronDown, ChevronUp, Upload } from "lucide-react";

// Define the validation schema
const schema = z.object({
  first_name: z.string().min(1, { message: "First name is required!" }),
  last_name: z.string().min(1, { message: "Last name is required!" }),
  phone_number: z.string().min(1, { message: "Phone number is required!" }),
  email: z.string().email({ message: "Invalid email address!" }),
  address: z.string().min(1, { message: "Address is required!" }),
  status: z.enum(["active", "inactive"], { message: "Status is required!" }),
  salary: z.coerce.number().nonnegative({ message: "Salary must be positive!" }),
  profile_image: z.any().optional(),
  schools_assistant: z.array(z.object({ id: z.number(), name: z.string() })).optional(),
});

const AssistantForm = ({ type, data, schools, setOpen }) => {
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [imagePreview, setImagePreview] = useState(data?.profile_image || null);
  const { errors: pageErrors } = usePage().props;
  const [loading, setLoading] = useState(false);
  const {
    register,
    handleSubmit,
    control,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      first_name: data?.first_name || "",
      last_name: data?.last_name || "",
      phone_number: data?.phone_number || "",
      email: data?.email || "",
      address: data?.address || "",
      status: data?.status || "active",
      salary: data?.salary || 0,
      profile_image: null,
      schools_assistant: data?.schools_assistant?.map(({ id, name }) => ({ id, name })) || [],
    },
  });

  const handleImageChange = (e) => {
    const file = e.target.files[0];
    if (file) {
      setValue('profile_image', file);
      // Create a preview URL
      const reader = new FileReader();
      reader.onload = (e) => {
        setImagePreview(e.target.result);
      };
      reader.readAsDataURL(file);
    }
  };

  // Inside the onSubmit function of your AssistantForm component
const onSubmit = handleSubmit((formData) => {
  // Create a FormData object to properly handle file uploads
  const formDataObj = new FormData();
  
  // Append all form fields
  formDataObj.append('first_name', formData.first_name);
  formDataObj.append('last_name', formData.last_name);
  formDataObj.append('phone_number', formData.phone_number);
  formDataObj.append('email', formData.email);
  formDataObj.append('address', formData.address);
  formDataObj.append('status', formData.status);
  formDataObj.append('salary', formData.salary);
  
  // Append the file if it exists
  if (formData.profile_image) {
    formDataObj.append('profile_image', formData.profile_image);
  }
  
  // Append each school ID
  formData.schools_assistant.forEach((school, index) => {
    formDataObj.append(`schools[${index}]`, school.id);
  });
  setLoading(true);
  if (type === "create") {
    router.post("/assistants", formDataObj, {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => {
        setOpen(false);
        setLoading(false);
      },
      onError: (errors) => {console.log("Inertia Errors:", errors); setLoading(false);},
    });
  } else if (type === "update") {
    // For update, we need to use the proper method spoofing with Inertia
    // Add the _method field to the formData for Laravel to recognize it as PUT
    formDataObj.append('_method', 'PUT');
    
    // Then use post() instead of put() because file uploads require POST
    router.post(`/assistants/${data.id}`, formDataObj, {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => {
        setOpen(false);
      },
      onError: (errors) => console.log("Inertia Errors:", errors),
    });
  }
});

  // Watch the form fields for changes
  const firstName = watch("first_name");
  const lastName = watch("last_name");
  const email = watch("email");

  // Prepare user data for the Register component
  const userData = {
    name: `${firstName} ${lastName}`,
    email: email,
  };

  return (
    <div className="flex flex-col gap-4">
      <form className="flex flex-col gap-8" onSubmit={onSubmit} encType="multipart/form-data">
        <h1 className="text-xl font-semibold">
          {type === "create" ? "Create a New Assistant" : "Update Assistant"}
        </h1>

        {/* Personal Information */}
        <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
          <InputField 
            label="First Name" 
            name="first_name" 
            register={register} 
            error={errors.first_name?.message || pageErrors?.first_name?.[0]} 
          />
          <InputField 
            label="Last Name" 
            name="last_name" 
            register={register} 
            error={errors.last_name?.message || pageErrors?.last_name?.[0]} 
          />
          <InputField 
            label="Phone Number" 
            name="phone_number" 
            register={register} 
            error={errors.phone_number?.message || pageErrors?.phone_number?.[0]} 
          />
          <InputField 
            label="Email" 
            name="email" 
            register={register} 
            error={errors.email?.message || pageErrors?.email} 
          />
          <InputField 
            label="Address" 
            name="address" 
            register={register} 
            error={errors.address?.message || pageErrors?.address?.[0]} 
          />

          {/* Status Dropdown */}
          <div className="flex flex-col gap-2 w-full">
            <label className="text-xs text-gray-500">Status</label>
            <select
              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-black focus:ring-black pr-10"
              {...register("status")}
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
            {(errors.status?.message || pageErrors?.status?.[0]) && (
              <p className="text-xs text-red-400">{errors.status?.message || pageErrors?.status?.[0]}</p>
            )}
          </div>
      
          {/* Salary Field */}
          <InputField 
            label="Salary" 
            name="salary" 
            type="number" 
            register={register} 
            error={errors.salary?.message || pageErrors?.salary?.[0]} 
          />

          {/* Multi-Select for Schools */}
          <div className="flex flex-col gap-2 w-full">
            <label className="text-xs text-gray-500">Schools</label>
            <Controller
              name="schools_assistant"
              control={control}
              render={({ field }) => (
                <Select
                  {...field}
                  options={schools}
                  isMulti
                  getOptionLabel={(e) => e.name}
                  getOptionValue={(e) => e.id.toString()}
                  onChange={(val) => field.onChange(val)}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-black focus:ring-black pr-10"
                  classNamePrefix="select"
                />
              )}
            />
            {(errors.schools_assistant?.message || pageErrors?.schools_assistant?.[0]) && (
              <p className="text-xs text-red-400">{errors.schools_assistant?.message || pageErrors?.schools_assistant?.[0]}</p>
            )}
          </div>

          {/* Profile Image Upload */}
          <div className="flex flex-col gap-2 w-full">
            <label className="text-xs text-gray-500">Profile Image</label>
            <div className="flex flex-col gap-2">
              {imagePreview && (
                <div className="relative w-24 h-24 mb-2">
                  <img 
                    src={imagePreview} 
                    alt="Profile preview" 
                    className="w-full h-full object-cover rounded-md"
                  />
                </div>
              )}
              <label 
                htmlFor="profile_image" 
                className="flex items-center gap-2 cursor-pointer p-2 border border-gray-300 rounded-md hover:bg-gray-50 transition-colors duration-200 w-full"
              >
                <Upload className="w-4 h-4 text-gray-500" />
                <span className="text-sm text-gray-700">
                  {imagePreview ? "Change image" : "Upload image"}
                </span>
                <input
                  id="profile_image"
                  type="file"
                  accept="image/png, image/jpeg, image/jpg"
                  className="hidden"
                  onChange={handleImageChange}
                />
              </label>
              {(errors.profile_image?.message || pageErrors?.profile_image?.[0]) && (
                <p className="text-xs text-red-400">{errors.profile_image?.message || pageErrors?.profile_image?.[0]}</p>
              )}
            </div>
          </div>

          <button
            type="button"
            onClick={() => setIsModalOpen(!isModalOpen)}
            className="items-center mt-7 h-10 inline-flex gap-2 bg-blue-500 hover:bg-blue-600 transition-all text-white px-4 py-2 rounded-md shadow-sm"
          >
            {isModalOpen ? (
              <ChevronUp className="w-4 h-4" />
            ) : (
              <ChevronDown className="w-4 h-4" />
            )}
            <span>Add User</span>
          </button>
        </div>
       
            <button 
      type="submit" 
      disabled={loading}
      className={`bg-blue-500 hover:bg-blue-600 transition-all text-white p-2 rounded-md flex items-center justify-center ${
        loading ? "opacity-70 cursor-not-allowed" : ""
      }`}
    >
      {loading ? (
        <span className="flex items-center gap-2">
          <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          Processing...
        </span>
      ) : (
        <span>{type === "create" ? "Create" : "Update"}</span>
      )}
    </button>

      </form>
     
      {isModalOpen && (
        <Register
          setIsModalOpen={setIsModalOpen}
          table="assistants"
          role={"assistant"}
          isModalOpen={isModalOpen}
          UserData={userData}
        />
      )}
    </div>
  );
};

export default AssistantForm;