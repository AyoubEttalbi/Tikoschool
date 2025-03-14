import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { router } from "@inertiajs/react"; // Inertia router for navigation
import InputField from "../InputField";

// Define the schema for subject form
const schema = z.object({
  name: z.string().min(1, { message: "Subject name is required!" }),
});

const SubjectForm = ({ type, data,setOpen }) => {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      name: data?.name || "",
    },
  });

  const onSubmit = (formData) => {
    if (type === "create") {
      router.post("/othersettings/subjects", formData, {
        onSuccess: () => {
          setOpen(false);
        },
      });
    } else if (type === "update") {
      router.put(`/othersettings/${data.id}`, formData, {
        onSuccess: () => {
          setOpen(false);
        },
      });
    }
  };

  return (
    <form
      className="flex flex-col gap-6 p-6 bg-white shadow-lg rounded-lg"
      onSubmit={handleSubmit(onSubmit)}
    >
      <h1 className="text-2xl font-semibold text-gray-800">
        {type === "create" ? "Create Subject" : "Update Subject"}
      </h1>
      <div className="grid grid-cols-1 gap-4">
      <InputField
        label="Subject Name"
        name="name"
        register={register}
        error={errors.name}
        defaultValue={data?.name}
      />

      {errors.name && <p className="text-xs text-red-500">{errors.name.message}</p>}

      <button
        type="submit"
        className="bg-blue-500 text-white p-3 rounded-md  hover:bg-blue-600 transition duration-200"
      >
        {type === "create" ? "Create" : "Update"}
      </button>
      </div>
    </form>
  );
};

export default SubjectForm;