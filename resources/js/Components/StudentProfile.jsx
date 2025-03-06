import AbsenceLogTable from "./AbsenceLogTable";
import InvoicesTable from "./InvoicesTable";
import ScoresTable from "./ScoresTable";

const StudentProfile = () => {

  const invoices = [
    {
      billDate: '2024-06',
      creationDate: '04-Jun-2024 19:24',
      amount: 300,
      rest: 0,
      pack: 'Offer: AC MATH SVT',
    },
  ];

  const absences = [
    {
      date: '2024-12-04',
      level: '3 AC',
      teacher: 'hamouda chakiri',
      status: 'Absent',
      note: '',
    },
  ];

  const scores = [
    {
      level: '3 AC',
      teacher: 'hamouda chakiri',
      grade1: '',
      grade2: '',
      grade3: '',
      note: '',
    },
  ];

  return (
    <div className="p-8 bg-white">
      
      <InvoicesTable invoices={invoices} />
      <AbsenceLogTable absences={absences} />
      <ScoresTable scores={scores} />
    </div>
  );
};

export default StudentProfile;