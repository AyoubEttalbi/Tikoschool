const InputField = ({
  label,
  type = "text",
  register,
  name,
  defaultValue,
  error,
  inputProps,
}) => {
  return (
    <div className="flex flex-col gap-2 w-full ">
      <label className="text-xs text-gray-500">{label}</label>
      <input
        type={type}
        {...register(name)}
        className="mt-1 block w-full rounded-md ring-[1.5px] ring-gray-300 p-2  text-sm  shadow-sm focus:border-black focus:ring-black pr-10"
        {...inputProps}
        defaultValue={defaultValue}
        min={0}
      />
      {/* Handle both string and object error types */}
      {error && (
        <p className="text-xs text-red-400">
          {typeof error === "string" ? error : error.message}
        </p>
      )}
    </div>
  );
};

export default InputField;