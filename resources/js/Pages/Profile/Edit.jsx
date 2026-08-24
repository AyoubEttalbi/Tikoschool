import { useRef, useState } from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import DeleteUserForm from "./Partials/DeleteUserForm";
import UpdatePasswordForm from "./Partials/UpdatePasswordForm";
import UpdateProfileInformationForm from "./Partials/UpdateProfileInformationForm";

function AvatarCard({ avatarUrl }) {
    const photoInput = useRef(null);
    const [preview, setPreview] = useState(avatarUrl);
    const { data, setData, post, processing, reset } = useForm({ photo: null });

    const handlePick = (e) => {
        const file = e.target.files[0];
        if (!file) return;
        setData("photo", file);
        setPreview(URL.createObjectURL(file));
    };

    const handleSave = (e) => {
        e.preventDefault();
        post(route("profile.image.upload"), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                if (photoInput.current) photoInput.current.value = "";
            },
            onError: () => {
                // keep the local preview; the field error renders below
            },
        });
    };

    const handleRemove = () => {
        router.delete(route("profile.image.remove"), {
            preserveScroll: true,
            onFinish: () => {
                setPreview(null);
                reset();
                if (photoInput.current) photoInput.current.value = "";
            },
        });
    };

    const errors = usePage().props.errors;

    return (
        <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
            <h2 className="text-lg font-medium text-gray-900">Photo de profil</h2>

            <div className="mt-4 flex items-center gap-6">
                {preview ? (
                    <img
                        src={preview}
                        alt="Photo de profil"
                        className="w-24 h-24 rounded-full object-cover ring-2 ring-gray-200"
                    />
                ) : (
                    <div className="w-24 h-24 rounded-full bg-gray-100 flex items-center justify-center text-gray-400 text-xl font-semibold">
                        ?
                    </div>
                )}

                <div className="flex flex-col gap-3">
                    <form onSubmit={handleSave} className="flex items-center gap-3">
                        <input
                            ref={photoInput}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={handlePick}
                            className="text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-md file:border-0 file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 file:cursor-pointer"
                        />
                        <button
                            type="submit"
                            disabled={!data.photo || processing}
                            className={`px-4 py-2 rounded-md text-sm text-white transition-colors ${
                                !data.photo || processing
                                    ? "bg-gray-300 cursor-not-allowed"
                                    : "bg-blue-500 hover:bg-blue-600"
                            }`}
                        >
                            {processing ? "Enregistrement..." : "Enregistrer la photo"}
                        </button>
                        {avatarUrl && (
                            <button
                                type="button"
                                onClick={handleRemove}
                                disabled={processing}
                                className="px-4 py-2 rounded-md text-sm bg-red-50 text-red-600 hover:bg-red-100 transition-colors cursor-pointer disabled:opacity-50"
                            >
                                Supprimer
                            </button>
                        )}
                    </form>

                    {errors.photo && (
                        <p className="text-xs text-red-500">{errors.photo}</p>
                    )}
                    <p className="text-xs text-gray-500">
                        JPG, PNG ou WebP. 5 Mo maximum. L&apos;image est recadrée et convertie en WebP.
                    </p>
                </div>
            </div>
        </div>
    );
}

export default function Edit({ mustVerifyEmail, status, avatarUrl }) {
    return (
        <DashboardLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Profile
                </h2>
            }
        >
            <Head title="Profile" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <AvatarCard avatarUrl={avatarUrl} />

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <DeleteUserForm className="max-w-xl" />
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
