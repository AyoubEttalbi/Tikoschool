import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { Upload } from "lucide-react";

// Update schema to include disease information
const schema = z
    .object({
        firstName: z.string().min(1, { message: "Le prénom est requis !" }),
        lastName: z.string().min(1, { message: "Le nom est requis !" }),
        dateOfBirth: z
            .string()
            .min(1, { message: "La date de naissance est requise !" }),
        billingDate: z
            .string()
            .min(1, { message: "La date de facturation est requise !" }),
        address: z.string().min(1, { message: "L'adresse est requise !" }),
        guardianNumber: z
            .string()
            .min(1, { message: "Le numéro du tuteur est requis !" }),
        CIN: z.string().optional(),
        phoneNumber: z.string().optional(),
        email: z.string().optional(),
        massarCode: z.string().optional(),
        levelId: z.string().min(1, { message: "Le niveau est requis !" }),
        classId: z.string().min(1, { message: "La classe est requise !" }),
        schoolId: z.string().min(1, { message: "L'école est requise !" }),
        status: z.enum(["active", "inactive"]).optional(),
        assurance: z.any().optional(),
        profile_image: z.any().optional(),
        hasDisease: z
            .union([z.literal(1), z.literal(0), z.literal("1"), z.literal("0")])
            .transform((val) => (val === 1 || val === "1" ? "1" : "0"))
            .optional(),
        diseaseName: z.any().optional(),
        medication: z.any().optional(),
    })
    .superRefine((data, ctx) => {
        // Only validate diseaseName if hasDisease is "1"
        if (
            data.hasDisease === "1" &&
            (!data.diseaseName || data.diseaseName.trim() === "")
        ) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                message: "Le nom de la maladie est requis lorsque l'élève a une maladie.",
                path: ["diseaseName"],
            });
        }
    });

const StudentForm = ({ type, data, levels, classes, schools, setOpen }) => {
    const defaultBillingDate = new Date().toISOString().split("T")[0];

    // State to track selected values for shadcn/ui Select components
    const [selectedLevel, setSelectedLevel] = useState(
        data?.levelId?.toString() || "",
    );
    const [selectedClass, setSelectedClass] = useState(
        data?.classId?.toString() || "",
    );
    const [selectedSchool, setSelectedSchool] = useState(
        data?.schoolId?.toString() || "",
    );
    const [selectedStatus, setSelectedStatus] = useState(
        data?.status || "active",
    );
    const [selectedAssurance, setSelectedAssurance] = useState(
        data?.assurance === 1 ? "1" : "0",
    );
    const [selectedHasDisease, setSelectedHasDisease] = useState(
        data?.hasDisease === 1 ? "1" : "0",
    );

    // State for image preview
    const [imagePreview, setImagePreview] = useState(
        data?.profile_image || null,
    );

    // State for loading
    const [loading, setLoading] = useState(false);

    // State for validation errors not caught by the form
    const [serverErrors, setServerErrors] = useState({});

    // State to hold filtered classes based on selected level
    const [filteredClasses, setFilteredClasses] = useState([]);

    const {
        register,
        handleSubmit,
        formState: { errors },
        setValue,
        watch,
    } = useForm({
        resolver: zodResolver(schema),
        defaultValues: {
            billingDate: defaultBillingDate,
            assurance: data?.assurance === 1 ? "1" : "0",
            status: data?.status || "active",
            hasDisease: data?.hasDisease === 1 ? "1" : "0",
            diseaseName:
                data?.hasDisease === 1 ? data?.diseaseName || "-" : "-",
            medication: data?.hasDisease === 1 ? data?.medication || "-" : "-",
            profile_image: null,
            ...data, // Spread existing data for update
        },
    });

    // Watch for hasDisease value changes
    const hasDisease = watch("hasDisease");

    // Handle image change
    const handleImageChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setValue("profile_image", file);
            // Create a preview URL
            const reader = new FileReader();
            reader.onload = (e) => {
                setImagePreview(e.target.result);
            };
            reader.readAsDataURL(file);
        }
    };

    // Set default values when data is available
    useEffect(() => {
        if (data) {
            setValue("levelId", data.levelId?.toString());
            setValue("classId", data.classId?.toString());
            setValue("schoolId", data.schoolId?.toString());
            setSelectedLevel(data.levelId?.toString());
            setSelectedClass(data.classId?.toString());
            setSelectedSchool(data.schoolId?.toString());
            setSelectedStatus(data.status);
            setSelectedAssurance(data.assurance === 1 ? "1" : "0");
            setSelectedHasDisease(data.hasDisease === 1 ? "1" : "0");

            // Set image preview if exists
            if (data.profile_image) {
                setImagePreview(data.profile_image);
            }
        }
    }, [data, setValue]);

    // Filter classes when level changes
    useEffect(() => {
        if (selectedLevel && classes) {
            // Filter classes that match the selected level_id
            const classesForLevel = classes.filter(
                (cls) => cls.level_id.toString() === selectedLevel,
            );
            setFilteredClasses(classesForLevel);

            // If current selected class is not in filtered list, reset it
            if (
                selectedClass &&
                !classesForLevel.some(
                    (cls) => cls.id.toString() === selectedClass,
                )
            ) {
                setSelectedClass("");
                setValue("classId", "");
            }
        } else {
            setFilteredClasses([]);
        }
    }, [selectedLevel, classes, setValue]);

    // Handle form submission
    const onSubmit = handleSubmit((formData) => {
        setServerErrors({});
        if (
            formData.hasDisease === "1" &&
            (!formData.diseaseName || formData.diseaseName.trim() === "")
        ) {
            setServerErrors({
                diseaseName:
                    "Le nom de la maladie est requis lorsque 'Oui' est sélectionné pour Maladie.",
            });
            return;
        }

        // Create a FormData object to properly handle file uploads
        const formDataObj = new FormData();

        // Append all form fields
        formDataObj.append("firstName", formData.firstName);
        formDataObj.append("lastName", formData.lastName);
        formDataObj.append("dateOfBirth", formData.dateOfBirth);
        formDataObj.append("billingDate", formData.billingDate);
        formDataObj.append("address", formData.address);
        formDataObj.append("guardianNumber", formData.guardianNumber);
        formDataObj.append("CIN", formData.CIN || "");
        formDataObj.append("phoneNumber", formData.phoneNumber || "");
        formDataObj.append("email", formData.email || "");
        formDataObj.append("massarCode", formData.massarCode || "");
        formDataObj.append("levelId", formData.levelId.toString());
        formDataObj.append("classId", formData.classId.toString());
        formDataObj.append("schoolId", formData.schoolId.toString());
        formDataObj.append("status", formData.status || "active");

        // Explicitly convert hasDisease to 0 or 1 (integer for PHP)
        const hasDiseaseValue =
            formData.hasDisease === "1" ||
            formData.hasDisease === 1 ||
            formData.hasDisease === true
                ? 1
                : 0;
        formDataObj.append("hasDisease", hasDiseaseValue); // Send as integer

        // Similarly ensure assurance is a numeric value
        const assuranceValue =
            formData.assurance === "1" ||
            formData.assurance === 1 ||
            formData.assurance === true
                ? 1
                : 0;
        formDataObj.append("assurance", assuranceValue); // Send as integer

        // Always explicitly set diseaseName and medication
        // If hasDisease is true, use provided values or default to empty
        // If hasDisease is false, always use empty string
        const diseaseNameValue =
            hasDiseaseValue === 1 ? formData.diseaseName || "" : "";
        const medicationValue =
            hasDiseaseValue === 1 ? formData.medication || "" : "";

        formDataObj.append("diseaseName", diseaseNameValue);
        formDataObj.append("medication", medicationValue);

        // Append the file if it exists
        if (formData.profile_image) {
            formDataObj.append("profile_image", formData.profile_image);
        }

        setLoading(true);
        if (type === "create") {
            // Send a POST request to create a new student
            router.post("/students", formDataObj, {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    setOpen(false);
                    setLoading(false);
                },
                onError: (errors) => {
                    setServerErrors(errors);
                    setLoading(false);
                },
            });
        } else if (type === "update") {
            // For update, we need to use the proper method spoofing with Inertia
            // Add the _method field to the formData for Laravel to recognize it as PUT
            formDataObj.append("_method", "PUT");

            // Then use post() instead of put() because file uploads require POST
            router.post(`/students/${data.id}`, formDataObj, {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    setOpen(false);
                    setLoading(false);
                },
                onError: (errors) => {
                    setServerErrors(errors);
                    setLoading(false);
                },
            });
        }
    });

    return (
        <form
            className="flex flex-col gap-8 p-6 "
            onSubmit={handleSubmit(onSubmit)}
        >
            <h1 className="text-2xl font-semibold text-gray-800">
                {type === "create" ? "Créer un nouvel élève" : "Mettre à jour l'élève"}
            </h1>
            <span className="text-xs text-gray-400 font-medium">
                Informations de l'élève
            </span>
            <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
                <InputField
                    label="Prénom"
                    name="firstName"
                    register={register}
                    error={errors.firstName}
                    defaultValue={data?.firstName}
                />
                <InputField
                    label="Nom"
                    name="lastName"
                    register={register}
                    error={errors.lastName}
                    defaultValue={data?.lastName}
                />
                <InputField
                    label="Date de naissance"
                    name="dateOfBirth"
                    register={register}
                    error={errors.dateOfBirth}
                    type="date"
                    defaultValue={data?.dateOfBirth}
                />
            </div>
            <span className="text-xs text-gray-400 font-medium">
                Informations supplémentaires
            </span>
            <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
                <InputField
                    label="Date de facturation"
                    name="billingDate"
                    register={register}
                    error={errors.billingDate}
                    type="date"
                    defaultValue={data?.billingDate || defaultBillingDate}
                />
                <InputField
                    label="Adresse"
                    name="address"
                    register={register}
                    error={errors.address}
                    defaultValue={data?.address}
                />
                <InputField
                    label="Numéro du tuteur"
                    name="guardianNumber"
                    register={register}
                    error={errors.guardianNumber}
                    defaultValue={data?.guardianNumber}
                />
            </div>
            <span className="text-xs text-gray-400 font-medium">
                Informations de contact
            </span>
            <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
                <InputField
                    label="CIN"
                    name="CIN"
                    register={register}
                    error={errors.CIN}
                    defaultValue={data?.CIN}
                />
                <InputField
                    label="Numéro de téléphone"
                    name="phoneNumber"
                    register={register}
                    error={errors.phoneNumber}
                    defaultValue={data?.phoneNumber}
                />
                <InputField
                    label="E-mail"
                    name="email"
                    register={register}
                    error={errors.email}
                    defaultValue={data?.email}
                />
            </div>
            <span className="text-xs text-gray-400 font-medium">
                Informations de santé
            </span>
            <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
                <div className="flex flex-col gap-2 w-full">
                    <label className="text-xs text-gray-600">Maladie</label>
                    <Select
                        value={selectedHasDisease}
                        onValueChange={(value) => {
                            setSelectedHasDisease(value);
                            setValue("hasDisease", value);
                            if (value === "0") {
                                setValue("diseaseName", "");
                                setValue("medication", "");
                                setServerErrors((prev) => {
                                    const newErrors = { ...prev };
                                    delete newErrors.diseaseName;
                                    delete newErrors.medication;
                                    return newErrors;
                                });
                            }
                        }}
                    >
                        <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
                            <SelectValue placeholder="Sélectionner une option" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="1">Oui</SelectItem>
                            <SelectItem value="0">Non</SelectItem>
                        </SelectContent>
                    </Select>
                    {errors.hasDisease?.message && (
                        <p className="text-xs text-red-400">
                            {errors.hasDisease.message}
                        </p>
                    )}
                </div>
                {(hasDisease === "1" || hasDisease === 1) && (
                    <>
                        <InputField
                            label="Nom de la maladie"
                            name="diseaseName"
                            register={register}
                            error={
                                errors.diseaseName ||
                                (serverErrors.diseaseName
                                    ? { message: serverErrors.diseaseName }
                                    : null)
                            }
                            defaultValue={data?.diseaseName}
                        />
                        <InputField
                            label="Médication"
                            name="medication"
                            register={register}
                            error={errors.medication}
                            defaultValue={data?.medication}
                        />
                    </>
                )}
            </div>
            <span className="text-xs text-gray-400 font-medium">
                Informations d'inscription
            </span>
            <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
                <InputField
                    label="Code Massar"
                    name="massarCode"
                    register={register}
                    error={errors.massarCode}
                    defaultValue={data?.massarCode}
                />
                <div className="flex flex-col gap-2 w-full">
                    <label className="text-xs text-gray-600">Niveau</label>
                    <Select
                        value={selectedLevel}
                        onValueChange={(value) => {
                            setSelectedLevel(value);
                            setValue("levelId", value);
                            setSelectedClass("");
                            setValue("classId", "");
                        }}
                    >
                        <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
                            <SelectValue placeholder="Sélectionner un niveau" />
                        </SelectTrigger>
                        <SelectContent>
                            {levels?.map((level) => (
                                <SelectItem
                                    key={level.id}
                                    value={level.id.toString()}
                                >
                                    {level.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.levelId?.message && (
                        <p className="text-xs text-red-400">
                            {errors.levelId.message}
                        </p>
                    )}
                </div>
                <div className="flex flex-col gap-2 w-full">
                    <label className="text-xs text-gray-600">Classe</label>
                    <Select
                        value={selectedClass}
                        onValueChange={(value) => {
                            setSelectedClass(value);
                            setValue("classId", value);
                        }}
                        disabled={
                            !selectedLevel || filteredClasses.length === 0
                        }
                    >
                        <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
                            <SelectValue
                                placeholder={
                                    !selectedLevel
                                        ? "Sélectionner un niveau d'abord"
                                        : filteredClasses.length === 0
                                          ? "Aucune classe pour ce niveau"
                                          : "Sélectionner une classe"
                                }
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {filteredClasses.length > 0 ? (
                                filteredClasses.map((classe) => (
                                    <SelectItem
                                        key={classe.id}
                                        value={classe.id.toString()}
                                    >
                                        {classe.name} (
                                        {classe.school_id
                                            ? `École : ${schools.find((school) => school.id === classe.school_id)?.name}`
                                            : "Aucune école"}
                                        )
                                    </SelectItem>
                                ))
                            ) : (
                                <div className="p-2 text-sm text-gray-500">
                                    {!selectedLevel
                                        ? "Sélectionner un niveau d'abord"
                                        : "Aucune classe pour ce niveau"}
                                </div>
                            )}
                        </SelectContent>
                    </Select>
                    {errors.classId?.message && (
                        <p className="text-xs text-red-400">
                            {errors.classId.message}
                        </p>
                    )}
                </div>
                <div className="flex flex-col gap-2 w-full">
                    <label className="text-xs text-gray-600">École</label>
                    <Select
                        value={selectedSchool}
                        onValueChange={(value) => {
                            setSelectedSchool(value);
                            setValue("schoolId", value);
                        }}
                    >
                        <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
                            <SelectValue placeholder="Sélectionner une école" />
                        </SelectTrigger>
                        <SelectContent>
                            {schools?.map((school) => (
                                <SelectItem
                                    key={school.id}
                                    value={school.id.toString()}
                                >
                                    {school.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.schoolId?.message && (
                        <p className="text-xs text-red-400">
                            {errors.schoolId.message}
                        </p>
                    )}
                </div>
                <div className="flex flex-col gap-2 w-full">
                    <label className="text-xs text-gray-600">Statut</label>
                    <Select
                        value={selectedStatus}
                        onValueChange={(value) => {
                            setSelectedStatus(value || "active");
                            setValue("status", value);
                        }}
                    >
                        <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
                            <SelectValue placeholder="Sélectionner un statut" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Actif</SelectItem>
                            <SelectItem value="inactive">Inactif</SelectItem>
                        </SelectContent>
                    </Select>
                    {errors.status?.message && (
                        <p className="text-xs text-red-400">
                            {errors.status.message}
                        </p>
                    )}
                </div>
                <div className="flex flex-col gap-2 w-full">
                    <label className="text-xs text-gray-600">Assurance</label>
                    <Select
                        value={selectedAssurance}
                        onValueChange={(value) => {
                            setSelectedAssurance(value);
                            setValue("assurance", value);
                        }}
                    >
                        <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
                            <SelectValue placeholder="Sélectionner l'assurance" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="1">Oui</SelectItem>
                            <SelectItem value="0">Non</SelectItem>
                        </SelectContent>
                    </Select>
                    {errors.assurance?.message && (
                        <p className="text-xs text-red-400">
                            {errors.assurance.message}
                        </p>
                    )}
                </div>
            </div>
            <div className="flex flex-col gap-2 w-full">
                <label className="text-xs text-gray-600">Photo de profil</label>
                <div className="flex flex-col gap-2">
                    {imagePreview && (
                        <div className="relative w-24 h-24 mb-2">
                            <img
                                src={imagePreview}
                                alt="Aperçu du profil"
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
                            {imagePreview ? "Changer l'image" : "Télécharger l'image"}
                        </span>
                        <input
                            id="profile_image"
                            type="file"
                            accept="image/png, image/jpeg, image/jpg"
                            className="hidden"
                            onChange={handleImageChange}
                        />
                    </label>
                    {errors.profile_image && (
                        <p className="text-xs text-red-400">
                            {errors.profile_image.message}
                        </p>
                    )}
                </div>
            </div>
            <button
                type="submit"
                disabled={loading}
                className={`bg-blue-400 text-white py-2 px-3 rounded-md mt-6 hover:bg-blue-500 transition duration-200 flex items-center justify-center ${
                    loading ? "opacity-70 cursor-not-allowed" : ""
                }`}
            >
                {loading ? (
                    <span className="flex items-center gap-2">
                        <svg
                            className="animate-spin h-4 w-4 text-white"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                className="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                strokeWidth="4"
                            ></circle>
                            <path
                                className="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            ></path>
                        </svg>
                        Traitement...
                    </span>
                ) : (
                    <span>{type === "create" ? "Créer" : "Mettre à jour"}</span>
                )}
            </button>
        </form>
    );
};

export default StudentForm;
