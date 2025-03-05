import React, { lazy, Suspense, useState } from 'react';

const OfferForm = lazy(() => import('./forms/OfferForm'));
const TeacherForm = lazy(() => import('./forms/TeacherForm'));
const StudentForm = lazy(() => import('./forms/StudentForm'));
const ClasseForm = lazy(() => import('./forms/ClasseForm'));

const forms = {
  offer: OfferForm,
  teacher: TeacherForm,
  student: StudentForm,
  class: ClasseForm,
};

const FormModal = ({ table, type, data, id }) => {
  const size = type === "create" ? "w-8 h-8" : "w-7 h-7";
  const bgColor =
    type === "create"
      ? "bg-lamaYellow"
      : type === "update"
      ? "bg-lamaYellow"
      : "bg-lamaPurple";

  const [open, setOpen] = useState(false);

  const Form = () => {
    if (type === "create" || type === "update") {
      const FormComponent = forms[table];
      return FormComponent ? (
        <Suspense fallback={<h1>Loading...</h1>}>
          <FormComponent type={type} data={data} />
        </Suspense>
      ) : (
        <p>Form not found!</p>
      );
    }
  };

  return (
    <>
      <button
        className={`${size} flex items-center justify-center rounded-full ${bgColor}`}
        onClick={() => setOpen(true)}
      >
        <img src={`/${type}.png`} alt="" width={16} height={16} />
      </button>
      {open && (
        <div className="w-screen h-screen absolute left-0 top-0 bg-black bg-opacity-60 z-50 flex items-center justify-center">
          <div className="bg-white p-4 rounded-md relative w-[90%] md:w-[70%] lg:w-[60%] xl:w-[50%] 2xl:w-[40%]">
            <Form />
            <div
              className="absolute top-4 right-4 cursor-pointer"
              onClick={() => setOpen(false)}
            >
              <img src="/close.png" alt="" width={14} height={14} />
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default FormModal;