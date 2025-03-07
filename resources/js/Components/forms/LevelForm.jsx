import { zodResolver } from "@hookform/resolvers/zod"
import { useForm } from "react-hook-form"
import { z } from "zod"
import InputField from "../InputField"
import { router } from "@inertiajs/react"

const levelSchema = z.object({
    name: z.string().min(1, { message: "Level name is required!" })
})

const LevelForm = ({ type, data }) => {
    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm({
        resolver: zodResolver(levelSchema),
        defaultValues: {
            name: data?.name || ""
        }
    })

    const onSubmit = (formData) => {
        if (type === "create") {
            router.post("/othersettings", formData)
        } else if (type === "update") {
            router.put(`/othersettings/${data.id}`, formData)
        }
    }

    return (
        <form
            className="flex flex-col gap-6 p-6 bg-white shadow-lg rounded-lg"
            onSubmit={handleSubmit(onSubmit)}
        >
            <h1 className="text-2xl font-semibold text-gray-800">
                {type === "create" ? "Create a New Level" : "Update Level"}
            </h1>

            <InputField
                label="Level Name"
                name="name"
                register={register}
                error={errors.name}
                defaultValue={data?.name}
            />

            <button
                type="submit"
                className="bg-blue-500 text-white p-3 rounded-md hover:bg-blue-600 transition"
            >
                {type === "create" ? "Create" : "Update"}
            </button>
        </form>
    )
}

export default LevelForm
