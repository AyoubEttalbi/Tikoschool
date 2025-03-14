import AbsenceLogTable from "./AbsenceLogTable";
import InvoicesTable from "./InvoicesTable";
import ScoresTable from "./ScoresTable";

const StudentProfile = ({Student_memberships,invoices = []}) => {
  
  

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
      
      <InvoicesTable invoices={invoices} Student_memberships={Student_memberships}/>
      <AbsenceLogTable absences={absences} />
      <ScoresTable scores={scores} />
    </div>
  );
};

export default StudentProfile;