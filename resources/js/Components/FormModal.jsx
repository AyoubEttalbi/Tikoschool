import { Edit, Plus } from 'lucide-react';
import React, { lazy, Suspense, useState } from 'react';
import DeleteConfirmation from './DeleteConfirmation';

const OfferForm = lazy(() => import('./forms/OfferForm'));
const TeacherForm = lazy(() => import('./forms/TeacherForm'));
const StudentForm = lazy(() => import('./forms/StudentForm'));
const ClasseForm = lazy(() => import('./forms/ClasseForm'));
const MembershipForm = lazy(() => import('./forms/MembershipForm'));
const AssistantForm = lazy(() => import('./forms/AssistantForm'));
const SubjectForm = lazy(() => import('./forms/SubjectForm'));
const LevelForm = lazy(() => import('./forms/LevelForm'));

const forms = {
  offer: OfferForm,
  teacher: TeacherForm,
  student: StudentForm,
  assistant: AssistantForm,
  class: ClasseForm,
  membership: MembershipForm,
  subject: SubjectForm,
  level: LevelForm,
};

const FormModal = ({ table, type, data, id, levels, route,subjects,classes, schools }) => {
  // console.log('classes frmaldata',classes);
  const size = type === "create" ? "w-8 h-8" : "w-7 h-7";
  const bgColor =
    type === "create" || type === "update"
      ? "bg-lamaYellow"
      : "bg-lamaPurple";

  const [open, setOpen] = useState(false);

  const Form = () => {


    const FormComponent = forms[table];
    return FormComponent ? (
      <Suspense fallback={<h1>Loading...</h1>}>
        <FormComponent type={type} data={data} levels={levels} schools={schools} subjects={subjects} classes={classes} setOpen={setOpen} />
      </Suspense>
    ) : (
      <p>Form not found!</p>
    );
  };

  return (
    <>
      {table === "membership" && type === "create" ? (
        <button
          className="bg-blue-500 hover:bg-blue-600 text-white rounded-full px-3 py-1 h-8 flex items-center"
          onClick={() => setOpen(true)}
        >
          <Plus className="h-4 w-4 mr-1" />
          New
        </button>
      ) : table === "membership" && type === "update" ? (
        <button
          className="text-blue-500 hover:text-blue-600"
          onClick={() => setOpen(true)}
        >
          <Edit className="h-5 w-5" />
        </button>
      ) : (
        <button
          className={`${size} flex items-center justify-center rounded-full ${bgColor}`}
          onClick={() => setOpen(true)}
        >
          <img src={`/${type}.png`} alt="" width={16} height={16} />
        </button>
      )}

      {open && (
        <div className="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center">
          {type === "delete" ? (
            <DeleteConfirmation
                route={route}
                id={id}
                onDelete={() => {
                    console.log('Delete confirmed');
                    setOpen(false); // Close the modal after deletion
                }}
                onClose={() => setOpen(false)} // Pass onClose to close the modal
            />
        ) : (
            <div className="bg-white modal-scrollable p-6 rounded-lg shadow-lg relative w-[90%] md:w-[70%] lg:w-[60%] xl:w-[50%] 2xl:w-[40%] max-h-[90vh] overflow-y-auto">
              <Form />
              <button
                className="absolute top-4 right-4 p-2 rounded-full bg-gray-200 hover:bg-gray-300"
                onClick={() => setOpen(false)}
              >
                <img src="/close.png" alt="Close" width={16} height={16} />
              </button>
            </div>
          )}
        </div>
      )}
    </>
  );
};

export default FormModal;