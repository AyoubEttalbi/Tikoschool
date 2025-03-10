import Select from "react-select";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, Controller } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router, Link } from "@inertiajs/react";
// Define the schema
const schema = z.object({
  firstName: z.string().min(1, { message: "First name is required!" }),
  lastName: z.string().min(1, { message: "Last name is required!" }),
  address: z.string().optional(),
  phoneNumber: z.string().optional(),
  email: z.string().email({ message: "Invalid email address!" }),
  status: z.enum(["active", "inactive"]),
  subjects: z.array(z.object({ id: z.number(), name: z.string() })).optional(),
  wallet: z.coerce.number().nonnegative({ message: "Wallet must be positive!" }),
  classes: z.array(z.object({ id: z.number(), name: z.string() })).optional(),
  schools: z.array(z.object({ id: z.number(), name: z.string() })).optional(),
  
});

const TeacherForm = ({ type, data, subjects, classes,schools ,setOpen}) => {
  console.log('from ',classes);
  const {
  register,
  handleSubmit,
  control,
  formState: { errors },
} = useForm({
  resolver: zodResolver(schema),
  defaultValues: {
    firstName: data?.first_name || "",
    lastName: data?.last_name || "",
    address: data?.address || "",
    phoneNumber: data?.phone_number || "",
    email: data?.email || "",
    status: data?.status || "active",
    subjects: data?.subjects || [],
    wallet: data?.wallet || 0,
    classes: data?.classes?.map(({ id, name }) => ({ id, name })) || [],  
    schools: data?.schools?.map(({ id, name }) => ({ id, name })) || [],
  },
});


  const onSubmit = handleSubmit((formData) => {

    const formattedData = {
      
      first_name: formData.firstName,
      last_name: formData.lastName,
      address: formData.address,
      phone_number: formData.phoneNumber,
      email: formData.email,
      status: formData.status,
      wallet: formData.wallet,
      subjects: formData.subjects.map((subj) => subj.id), 
      classes: formData.classes.map((group) => group.id),
      schools: formData.schools.map((school) => school.id), 
    };
  
    console.log(formattedData); 
  
    if (type === "create") {
      router.post("/teachers", formattedData, {
        onSuccess: () => {
          setOpen(false); 
        },
      });
    } else if (type === "update") {
      router.put(`/teachers/${data.id}`, formattedData);
    }
  });
  
  

  return (
    <div className="flex flex-col gap-4">
    <form className="flex flex-col gap-8" onSubmit={onSubmit}>
      <h1 className="text-xl font-semibold">
        {type === "create" ? "Create a New Teacher" : "Update Teacher"}
      </h1>

      {/* Personal Information */}
      <div className="flex flex-wrap gap-4">
        <InputField label="First Name" name="firstName" register={register} error={errors.firstName} />
        <InputField label="Last Name" name="lastName" register={register} error={errors.lastName} />
        <InputField label="Address" name="address" register={register} error={errors.address} />
        <InputField label="Phone Number" name="phoneNumber" register={register} error={errors.phoneNumber} />
        <InputField label="Email" name="email" register={register} error={errors.email} />
      </div>

      {/* Additional Information */}
      <div className="flex flex-wrap gap-4">
        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-500">Status</label>
          <select className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full" {...register("status")}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          {errors.status && <p className="text-xs text-red-400">{errors.status.message}</p>}
        </div>

        {/* Multi-Select for Subjects */}
        <div className="w-full md:w-1/4">
          <label className="text-xs text-gray-500">Subjects</label>
          <Controller
            name="subjects"
            control={control}
            render={({ field }) => (
              <Select
                {...field}
                options={subjects}
                isMulti
                getOptionLabel={(e) => e.name}
                getOptionValue={(e) => e.id.toString()}
                onChange={(val) => field.onChange(val)}
                className="basic-multi-select"
                classNamePrefix="select"
              />
            )}
          />
          {errors.subjects && <p className="text-xs text-red-400">{errors.subjects.message}</p>}
        </div>

        {/* Multi-Select for classes */}
        <div className="w-full md:w-1/4">
          <label className="text-xs text-gray-500">classes</label>
          <Controller
            name="classes"
            control={control}
            render={({ field }) => (
              <Select
                {...field}
                options={classes}
                isMulti
                getOptionLabel={(e) => e.name}
                getOptionValue={(e) => e.id.toString()}
                onChange={(val) => field.onChange(val)}
                className="basic-multi-select"
                classNamePrefix="select"
              />
            )}
          />
          {errors.classes && <p className="text-xs text-red-400">{errors.classes.message}</p>}
        </div>
        {/* Multi-Select for classes */}
        <div className="w-full md:w-1/4">
          <label className="text-xs text-gray-500">Schools</label>
          <Controller
            name="schools"
            control={control}
            render={({ field }) => (
              <Select
                {...field}
                options={schools}
                isMulti
                getOptionLabel={(e) => e.name}
                getOptionValue={(e) => e.id.toString()}
                onChange={(val) => field.onChange(val)}
                className="basic-multi-select"
                classNamePrefix="select"
              />
            )}
          />
          {errors.classes && <p className="text-xs text-red-400">{errors.classes.message}</p>}
        </div>

        <InputField label="Wallet Amount" name="wallet" type="number" register={register} error={errors.wallet} />
      </div>

      <button type="submit" className="bg-blue-500 text-white p-2 rounded-md">
        {type === "create" ? "Create" : "Update"}
      </button>
    </form>
    {type==="update" &&
    <Link  href={`/setting?data=${JSON.stringify(data)}`}>
  <button className="bg-blue-500 w-full text-white p-2 rounded-md">Go to User </button>
</Link>
}
    </div>
  );
};

export default TeacherForm;