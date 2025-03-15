import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, Controller } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router, usePage } from "@inertiajs/react";
import Select from "react-select";
import { useState } from "react";
import Register from "@/Pages/Auth/Register";
import { ChevronDown, ChevronUp } from "lucide-react";

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
  const { errors: pageErrors } = usePage().props;

  const {
    register,
    handleSubmit,
    control,
    watch,
    formState: { errors },
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
      profile_image: data?.profile_image || null,
      schools_assistant: data?.schools_assistant?.map(({ id, name }) => ({ id, name })) || [],
    },
  });

  const onSubmit = handleSubmit((formData) => {
    const formattedData = {
      first_name: formData.first_name,
      last_name: formData.last_name,
      phone_number: formData.phone_number,
      email: formData.email,
      address: formData.address,
      status: formData.status,
      salary: formData.salary,
      profile_image: formData.profile_image,
      schools: formData.schools_assistant.map((school) => school.id),
    };

    if (type === "create") {
      router.post("/assistants", formattedData, {
        preserveScroll: true,
        onError: (errors) => console.log("Inertia Errors:", errors),
      });
    } else if (type === "update") {
      router.put(`/assistants/${data.id}`, formattedData, {
        preserveScroll: true,
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
      <form className="flex flex-col gap-8" onSubmit={onSubmit}>
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
          <div className="flex flex-col gap-2 w-full ">
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
          <div className="flex flex-col gap-2 w-full ">
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
       
        <button type="submit" className="bg-blue-500 hover:bg-blue-600 transition-all text-white p-2 rounded-md">
          {type === "create" ? "Create" : "Update"}
        </button>
       
      </form>
     
     
      
        {isModalOpen && (
        <Register
          setIsModalOpen={setIsModalOpen}
          table="assistants"
          role={"assistant"}
          isModalOpen={isModalOpen}
          UserData={userData} // Pass the user data to the Register component
        />
      )}
      {/* Render the Register modal */}
      
    </div>
  );
};

export default AssistantForm;